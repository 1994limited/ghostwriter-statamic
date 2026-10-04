<?php

namespace NineteenNinetyFour\Ghostwriter\Subscribers;

use Illuminate\Events\Dispatcher;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Throwable;

/**
 * Finish this page compares a page with its collection's newest published
 * entries (which images most of them have). Those fill rates are kept
 * between checks, and counted again once an entry in the collection is
 * saved or deleted. Never stops a save.
 */
class RefreshGapRates
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EntrySaved::class, [self::class, 'changed']);
        $events->listen(EntryDeleted::class, [self::class, 'changed']);
    }

    public function changed(EntrySaved|EntryDeleted $event): void
    {
        try {
            EntryGaps::forgetRates($event->entry);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
