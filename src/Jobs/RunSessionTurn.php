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
use NineteenNinetyFour\Ghostwriter\Drafts\DraftLayouts;
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

    public function handle(SessionStore $sessions, SessionGuard $guard, TypeRepository $types, Studio $studio, GuideStore $guides, ImageStudio $images, DraftLayouts $layouts): void
    {
        $this->allowTimeToFinish();

        $session = $sessions->find($this->sessionId);

        if (! $session) {
            return;
        }

        // Images can be chosen while the writer works: the turn's own image
        // changes land only on fields nobody touched since it began.
        $imagesBefore = $session->images;
        $before = $session->draft;
        $answer = null;
        $error = null;
        $turn = null;
        $site = null;

        try {
            $type = $types->find($session->kind)
                ?? throw new InvalidArgumentException("The content type \"{$session->kind}\" no longer exists.");

            $turn = $studio->turn($session, $type->forSession($session), $guides->guide(Guide::VOICE)->body);
            $response = $turn[0];

            // Where the draft is going, for its layouts and extras.
            if ($response->document !== null) {
                $site = $layouts->safeContext($type->forSession($session));
            }

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
        $first = $answer?->document !== null && $site !== null && DraftLayouts::isFirst($before);

        // The first draft's other layouts are looked for once it is saved:
        // marked now, so the panel shows "Finding other layouts…" with it.
        if ($first) {
            DraftLayouts::planning($this->sessionId);
        }

        $guard->change($this->sessionId, function (Session $latest) use ($session, $imagesBefore, $answer, $error, $turn, $site, $layouts) {
            SessionImages::mergeTurn($latest, $session->images, $imagesBefore);

            if ($answer !== null) {
                $prior = $latest->draft;
                $latest->answer($answer->reply, $answer->document, $answer->inputTokens, $answer->outputTokens, Carbon::now());

                // The layouts follow the new draft: the writer's own at
                // once; a later turn's others re-arranged (no call).
                if ($answer->document !== null && $site !== null) {
                    $layouts->afterTurn($latest, $prior, $answer, $turn[1], $turn[2], $site);
                }
            } else {
                $latest->fail((string) $error);
            }
        });

        // Then, on the first draft only, one call to the layout planner.
        if ($first) {
            $layouts->plan($this->sessionId, $answer, $turn[1], $turn[2], $site);
        }
    }
}
