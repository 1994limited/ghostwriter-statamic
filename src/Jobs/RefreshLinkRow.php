<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Suggest\LinkRows;

/**
 * A taxonomy term was saved: its link row in the link index (SEO layer
 * §7.1) written again in the site, or forgotten. No model. Once a minute at
 * most per term and site.
 */
class RefreshLinkRow implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 60;

    public function __construct(public string $group, public string $id, public ?string $site = null) {}

    /**
     * Queued; on the sync driver, after the response, so a save is never
     * held up by it (and at once in the console and in tests).
     */
    public static function start(EntryRef $ref): void
    {
        $job = new self($ref->group, (string) $ref->id, $ref->site === null ? null : (string) $ref->site);

        if (config('queue.default') === 'sync' && ! app()->runningInConsole()) {
            app(Dispatcher::class)->dispatchAfterResponse($job);

            return;
        }

        dispatch($job);
    }

    public function uniqueId(): string
    {
        return $this->group.':'.$this->id.'@'.($this->site ?? '');
    }

    public function handle(LinkRows $rows): void
    {
        $rows->refresh(new EntryRef($this->group, $this->id, $this->site));
    }
}
