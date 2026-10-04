<?php

namespace NineteenNinetyFour\Ghostwriter\Suggest;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySnapshot;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviews;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Quieted;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Throwable;

/**
 * The entries Content to revisit reads: the published entries of the
 * collections Ghostwriter writes for, a site at a time, in chunks from the
 * Stache. A localisation that keeps nothing of its own is its origin's
 * page and is left to the origin's row.
 */
class StatamicEntrySource implements EntrySource
{
    public function __construct(private TypeRepository $types, private EntryChecks $checks) {}

    public function all(int|string|null $site = null, int $chunk = 200): iterable
    {
        foreach ($this->sites($site) as $handle) {
            foreach ($this->collections() as $collection) {
                $query = Entry::query()->where('collection', $collection)->where('site', $handle)->where('published', true);

                foreach ($query->lazy(max(1, $chunk)) as $entry) {
                    if ($entry instanceof EntryContract && ! self::inherits($entry) && ($snapshot = $this->snapshot($entry)) !== null) {
                        yield $snapshot;
                    }
                }
            }
        }
    }

    public function updatedSince(DateTimeInterface $since, int|string|null $site = null): iterable
    {
        foreach ($this->sites($site) as $handle) {
            foreach ($this->collections() as $collection) {
                foreach (Entry::query()->where('collection', $collection)->where('site', $handle)->lazy(200) as $entry) {
                    $updated = $entry instanceof EntryContract ? EntryChecks::updatedAt($entry) : null;

                    if ($updated !== null && $updated > $since) {
                        yield EntryChecks::ref($entry);
                    }
                }
            }
        }
    }

    public function find(EntryRef $ref): ?EntrySnapshot
    {
        $entry = Entry::find((string) $ref->id);

        if ($entry instanceof EntryContract && $ref->site !== null && $entry->locale() !== (string) $ref->site) {
            $entry = $entry->in((string) $ref->site);
        }

        if (! $entry instanceof EntryContract || ! in_array($entry->collectionHandle(), $this->collections(), true)) {
            return null;
        }

        return $this->snapshot($entry);
    }

    public function snapshot(EntryContract $entry): ?EntrySnapshot
    {
        try {
            return new EntrySnapshot(
                EntryChecks::ref($entry),
                (string) ($entry->get('title') ?? $entry->slug() ?? $entry->id()),
                $entry->editUrl(),
                // Decisions keep their findings off the list too: "It's still
                // right" for 12 months, or until the passage is edited.
                $this->checks->context($entry, quieted: $this->quieted($entry)),
                $entry->published() && $entry->status() !== 'draft',
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function quieted(EntryContract $entry): ?Quieted
    {
        try {
            return app(EditReviews::class)->quieted(EntryChecks::ref($entry), Carbon::now()->toDateTimeImmutable());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A localisation that holds no values of its own.
     */
    public static function inherits(EntryContract $entry): bool
    {
        return $entry->hasOrigin() && collect($entry->data()->all())->except(['updated_at', 'updated_by', 'id'])->isEmpty();
    }

    /**
     * @return list<string>
     */
    private function collections(): array
    {
        return $this->types->collections()->map->handle()->values()->all();
    }

    /**
     * @return list<string>
     */
    private function sites(int|string|null $site): array
    {
        return $site === null ? Site::all()->keys()->map(fn ($key) => (string) $key)->values()->all() : [(string) $site];
    }
}
