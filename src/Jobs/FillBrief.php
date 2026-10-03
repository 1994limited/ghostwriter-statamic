<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefThread;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Throwable;

/**
 * Fills in a piece's brief from the person's quick details (or a plan
 * idea), with one model call, and puts it in the conversation as a card to
 * check. "Try again" runs it once more with the card as the person left it.
 */
class FillBrief implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $sessionId) {}

    public function subject(): ?string
    {
        return 'session:'.$this->sessionId;
    }

    /**
     * The job a piece's next step needs: filling in its brief, or the
     * writer's turn.
     */
    public static function next(Session $session): void
    {
        BriefThread::fills($session) ? self::start($session->id) : RunSessionTurn::start($session->id);
    }

    public function handle(SessionStore $sessions, SessionGuard $guard, TypeRepository $types, Studio $studio): void
    {
        $this->allowTimeToFinish();

        $session = $sessions->find($this->sessionId);

        // Gone, or moved on (the brief was agreed meanwhile): nothing to do.
        if (! $session || ! BriefThread::fills($session)) {
            return;
        }

        try {
            $type = $types->find($session->kind)
                ?? throw new InvalidArgumentException("The content type \"{$session->kind}\" no longer exists.");

            $result = $studio->fillBrief($session, $type);

            $guard->propose($this->sessionId, $result->value, $result->usage->input, $result->usage->output, __(BriefThread::CARD_TEXT), __(BriefThread::OPEN_TEXT));
        } catch (Throwable $exception) {
            report($exception);

            $guard->change($this->sessionId, function (Session $latest) use ($exception) {
                if (! BriefThread::fills($latest)) {
                    return false;
                }

                $latest->fail($exception->getMessage());
            });
        }
    }
}
