<?php

namespace NineteenNinetyFour\Ghostwriter\Subscribers;

use Illuminate\Events\Dispatcher;
use NineteenNinetyFour\Ghostwriter\Jobs\RefreshRevisitEntry;
use NineteenNinetyFour\Ghostwriter\Suggest\Revisit;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Throwable;

/**
 * Keeps Content to revisit and Suggest edits' records current as entries
 * change: a save queues the entry's refresh (deduplicated), a delete
 * forgets it and checks again every page that linked to it, so a newly
 * broken link shows at once. No model; never stops a save.
 */
class KeepRevisitCurrent
{
    public function __construct(private TypeRepository $types) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EntrySaved::class, [self::class, 'entrySaved']);
        $events->listen(EntryDeleted::class, [self::class, 'entryDeleted']);
    }

    public function entrySaved(EntrySaved $event): void
    {
        if (! self::on()) {
            return;
        }

        try {
            if ($this->types->enabled((string) $event->entry->collectionHandle())) {
                RefreshRevisitEntry::start((string) $event->entry->id(), $event->entry->locale());
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function entryDeleted(EntryDeleted $event): void
    {
        if (! self::on()) {
            return;
        }

        try {
            app(Revisit::class)->deleted($event->entry);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Always, on a site. The addon's own tests switch it off where it
     * isn't what they test, as it reads assets while entries are saved.
     */
    private static function on(): bool
    {
        return (bool) config('ghostwriter.revisit.on_save', true);
    }
}
