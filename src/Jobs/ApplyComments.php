<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comments;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftLayouts;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Throwable;

/**
 * **Apply N comments**, in the background: one call to the reviser for
 * the comments in the editor's message (core's Comments::revise()),
 * checked and applied under the piece's lock, and Ghostwriter's answer in
 * the conversation. A provider failure is core's to report; anything else
 * that stops the run is answered the same way here (Comments::fail()), so
 * the piece is never left working.
 */
class ApplyComments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $sessionId) {}

    public function subject(): ?string
    {
        return 'session:'.$this->sessionId;
    }

    public function handle(SessionStore $sessions, Comments $comments, TypeRepository $types, Studio $studio, GuideStore $guides, DraftLayouts $layouts): void
    {
        $this->allowTimeToFinish();

        $session = $sessions->find($this->sessionId);

        if (! $session) {
            return;
        }

        try {
            $type = $types->find($session->kind)
                ?? throw new InvalidArgumentException("The content type \"{$session->kind}\" no longer exists.");
            $type = $type->forSession($session);
            $site = $layouts->context($type)
                ?? throw new InvalidArgumentException('The collection this was written for no longer exists.');

            [$conversation, $writer] = $studio->revision($session, $type, $guides->guide(Guide::VOICE)->body);

            $comments->revise($this->sessionId, $conversation, $writer, $site, self::names($session));
        } catch (Throwable $exception) {
            report($exception);

            $comments->fail($this->sessionId, $exception->getMessage());
        }
    }

    /**
     * The worker gave up on the job (a timeout): the run is answered as failed.
     */
    public function failed(?Throwable $exception = null): void
    {
        app(Comments::class)->fail($this->sessionId, 'it took too long.');
    }

    /**
     * Who wrote each comment and reply, for "By Priya" in the prompt.
     *
     * @return array<string, string>
     */
    private static function names(Session $session): array
    {
        $ids = [];

        $run = Comments::unanswered($session);

        foreach ($run === null ? [] : Comments::itemsOf($session->messages[$run]) as $comment) {
            if ($comment->by !== null) {
                $ids[(string) $comment->by] = true;
            }
        }

        $names = [];

        foreach (array_keys($ids) as $id) {
            $names[(string) $id] = Presenter::name((string) $id);
        }

        return $names;
    }
}
