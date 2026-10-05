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
 * "Try again" in the Text tab's Search section: one `seo-editor` call for
 * another search title and description (core's SeoPass::retryMeta()). The
 * panel shows "Writing another…" until it has finished, and says so when
 * the call failed.
 */
class RetrySearchMeta implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $sessionId) {}

    public function subject(): ?string
    {
        return 'search:'.$this->sessionId;
    }

    public function handle(SessionStore $sessions, TypeRepository $types, DraftLayouts $layouts): void
    {
        $this->allowTimeToFinish();

        $session = $sessions->find($this->sessionId);
        $type = $session ? $types->find($session->kind) : null;

        if ($type === null) {
            DraftLayouts::wroteAnother($this->sessionId);

            return;
        }

        $layouts->retryMeta($this->sessionId, $type);
    }
}
