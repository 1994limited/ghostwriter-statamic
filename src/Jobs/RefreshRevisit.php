<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Suggest\Revisit;

/**
 * Content to revisit's first pass, when the list is opened before the
 * scheduler has ever run it: every published page read once, no model.
 */
class RefreshRevisit implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function subject(): ?string
    {
        return 'revisit';
    }

    public function handle(Revisit $revisit): void
    {
        $this->allowTimeToFinish();
        $revisit->daily(full: true);
    }
}
