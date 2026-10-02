<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionImages;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Throwable;

/**
 * Sends a session's latest message to the writer and stores what comes back:
 * its reply, and the draft if it wrote or changed one.
 */
class RunSessionTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $sessionId) {}

    public function subject(): ?string
    {
        return 'session:'.$this->sessionId;
    }

    public function handle(SessionStore $sessions, SessionGuard $guard, TypeRepository $types, Studio $studio, GuideStore $guides, ImageStudio $images): void
    {
        $this->allowTimeToFinish();

        $session = $sessions->find($this->sessionId);

        if (! $session) {
            return;
        }

        // Images can be chosen while the writer works: the turn's own image
        // changes land only on fields nobody touched since it began.
        $imagesBefore = $session->images;
        $answer = null;
        $error = null;

        try {
            $type = $types->find($session->kind)
                ?? throw new InvalidArgumentException("The content type \"{$session->kind}\" no longer exists.");

            $response = $studio->write($session, $type->forSession($session), $guides->guide(Guide::VOICE)->body);

            // Images the writer asked for: photographs to offer or fill in,
            // or an image to have made. The draft decides which fields exist.
            if ($response->images !== null && ($response->document ?? $session->draft) !== null) {
                $session->draft = $response->document ?? $session->draft;
                $session = $images->carryOut($session, $type->forSession($session), $response->images);
            }

            $answer = $response;
        } catch (Throwable $exception) {
            report($exception);

            $error = $exception->getMessage();
        }

        // Saved onto the session as it stands now, under its lock. A piece
        // removed meanwhile stays removed.
        $guard->change($this->sessionId, function (Session $latest) use ($session, $imagesBefore, $answer, $error) {
            SessionImages::mergeTurn($latest, $session->images, $imagesBefore);

            if ($answer !== null) {
                $latest->answer($answer->reply, $answer->document, $answer->inputTokens, $answer->outputTokens, Carbon::now());
            } else {
                $latest->fail((string) $error);
            }
        });
    }
}
