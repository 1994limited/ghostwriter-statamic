<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftLayouts;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;

/**
 * "Refresh layouts": one call to the layout planner for new alternatives to
 * the draft as it stands. The writer's layout stays first.
 */
class RefreshLayouts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $sessionId) {}

    public function subject(): ?string
    {
        return 'layouts:'.$this->sessionId;
    }

    public function handle(SessionStore $sessions, TypeRepository $types, DraftLayouts $layouts): void
    {
        $this->allowTimeToFinish();

        $session = $sessions->find($this->sessionId);
        $type = $session ? $types->find($session->kind) : null;
        $site = $type ? $layouts->safeContext($type->forSession($session)) : null;

        if ($site === null) {
            DraftLayouts::planned($this->sessionId);

            return;
        }

        $layouts->refresh($this->sessionId, $site);
    }
}
