<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Suggest\Revisit;
use Statamic\Facades\Entry;

/**
 * An entry was saved: its Content to revisit row, its paragraphs for
 * Suggest edits' duplicate check, and its latest review's Done and Stale.
 * No model. Once a minute at most per entry and site: saving twice in a
 * row queues it once.
 */
class RefreshRevisitEntry implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 60;

    public function __construct(public string $entryId, public ?string $site = null) {}

    /**
     * Queued; on the sync driver, after the response, so a save is never
     * held up by it (and at once in the console and in tests).
     */
    public static function start(string $entryId, ?string $site = null): void
    {
        $job = new self($entryId, $site);

        if (config('queue.default') === 'sync' && ! app()->runningInConsole()) {
            app(Dispatcher::class)->dispatchAfterResponse($job);

            return;
        }

        dispatch($job);
    }

    public function uniqueId(): string
    {
        return $this->entryId.'@'.($this->site ?? '');
    }

    public function handle(Revisit $revisit): void
    {
        $entry = Entry::find($this->entryId);

        if ($entry && $this->site !== null && $entry->locale() !== $this->site) {
            $entry = $entry->in($this->site);
        }

        if ($entry) {
            $revisit->saved($entry);
        }
    }
}
