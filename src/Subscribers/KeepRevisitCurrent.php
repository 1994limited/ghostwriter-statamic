<?php

namespace NineteenNinetyFour\Ghostwriter\Subscribers;

use Illuminate\Events\Dispatcher;
use NineteenNinetyFour\Ghostwriter\Jobs\RefreshLinkRow;
use NineteenNinetyFour\Ghostwriter\Jobs\RefreshRevisitEntry;
use NineteenNinetyFour\Ghostwriter\Suggest\LinkRows;
use NineteenNinetyFour\Ghostwriter\Suggest\Revisit;
use NineteenNinetyFour\Ghostwriter\Suggest\StatamicLinkSource;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Events\CollectionSaved;
use Statamic\Events\CollectionTreeSaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\NavTreeSaved;
use Statamic\Events\TaxonomySaved;
use Statamic\Events\TermDeleted;
use Statamic\Events\TermSaved;
use Throwable;

/**
 * Keeps Content to revisit, Suggest edits' records and the link index
 * current as the site changes:
 *
 * - an entry saved in one of Ghostwriter's collections queues its refresh
 *   (deduplicated); one in any other collection with a route, or a
 *   taxonomy term, queues its link row (SEO layer §7.1);
 * - an entry or a term deleted is forgotten, and every page that linked to
 *   it is checked again, so a newly broken link shows at once;
 * - a collection's route, a collection's tree or a navigation changed:
 *   the group's link rows are written again on the next daily pass.
 *
 * No model; never stops a save.
 */
class KeepRevisitCurrent
{
    public function __construct(private TypeRepository $types) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EntrySaved::class, [self::class, 'entrySaved']);
        $events->listen(EntryDeleted::class, [self::class, 'entryDeleted']);
        $events->listen(TermSaved::class, [self::class, 'termSaved']);
        $events->listen(TermDeleted::class, [self::class, 'termDeleted']);
        $events->listen(CollectionSaved::class, [self::class, 'collectionSaved']);
        $events->listen(CollectionTreeSaved::class, [self::class, 'treeSaved']);
        $events->listen(NavTreeSaved::class, [self::class, 'navSaved']);
        $events->listen(TaxonomySaved::class, [self::class, 'taxonomySaved']);
    }

    public function entrySaved(EntrySaved $event): void
    {
        if (! self::on()) {
            return;
        }

        try {
            $entry = $event->entry;
            $collection = (string) $entry->collectionHandle();

            if ($this->types->enabled($collection)) {
                RefreshRevisitEntry::start((string) $entry->id(), $entry->locale());
            } elseif ($entry->collection()?->route($entry->locale()) !== null) {
                RefreshLinkRow::start(StatamicLinkSource::ref($entry));
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

    public function termSaved(TermSaved $event): void
    {
        if (! self::on()) {
            return;
        }

        try {
            foreach ($event->term->taxonomy()?->sites() ?? [] as $site) {
                $localized = $event->term->in((string) $site);

                if ($localized !== null) {
                    RefreshLinkRow::start(StatamicLinkSource::ref($localized));
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function termDeleted(TermDeleted $event): void
    {
        if (! self::on()) {
            return;
        }

        try {
            app(Revisit::class)->deletedTerm($event->term);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function collectionSaved(CollectionSaved $event): void
    {
        $this->mark((string) $event->collection->handle());
    }

    public function treeSaved(CollectionTreeSaved $event): void
    {
        $this->mark((string) ($event->tree->collection()?->handle() ?? LinkRows::ALL));
    }

    public function navSaved(NavTreeSaved $event): void
    {
        $this->mark(LinkRows::ALL);
    }

    public function taxonomySaved(TaxonomySaved $event): void
    {
        $this->mark(StatamicLinkSource::TAXONOMY.$event->taxonomy->handle());
    }

    private function mark(string $group): void
    {
        if (! self::on()) {
            return;
        }

        try {
            app(LinkRows::class)->mark($group);
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
