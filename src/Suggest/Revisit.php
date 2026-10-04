<?php

namespace NineteenNinetyFour\Ghostwriter\Suggest;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ExternalLinkCheck;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitIndex;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitOptions;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviews;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Storage\FileRevisitStore;
use NineteenNinetyFour\Ghostwriter\Storage\JsonFiles;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Site;
use Throwable;

/**
 * Keeps Content to revisit and Suggest edits' records in step with the
 * site, with no model:
 *
 * - saved(): one entry saved: its row, its paragraphs in the index, and
 *   its latest review's Done and Stale (core's Reconciler);
 * - deleted(): its row, index entry and reviews forgotten, and every
 *   page that linked to it checked again;
 * - daily(): entries saved since the last run and rows whose day has
 *   come, a full pass once a week (and the first time), and suggestions
 *   nobody acted on for 14 days expired;
 * - links(): the weekly check of links to other sites, only when a
 *   manager has turned it on.
 */
class Revisit
{
    use JsonFiles;

    /** Days between full passes. */
    public const FULL_EVERY = 7;

    public function __construct(
        private RevisitIndex $index,
        private RevisitStore $rows,
        private StatamicEntrySource $source,
        private EntryChecks $checks,
        private FileEntryIndex $entries,
        private EditReviews $reviews,
        private EditReviewStore $reviewStore,
        private TypeRepository $types,
        private Settings $settings,
        private StatamicLinkSource $links,
        private LinkRows $linkRows,
    ) {}

    public function saved(Entry $entry, ?DateTimeImmutable $now = null): void
    {
        $now ??= Carbon::now()->toDateTimeImmutable();
        $ref = EntryChecks::ref($entry);

        // Any other collection with a route: a link row only (SEO layer §7.1).
        if (! $this->types->enabled((string) $entry->collectionHandle())) {
            $this->linkRows->saved($entry);

            return;
        }

        $context = $this->checks->context($entry, now: $now);
        $row = $this->links->row($entry, IndexScope::Full);

        $this->entries->put($ref, (string) ($entry->get('title') ?? ''), $row->url, $context, self::summary($entry), $row);
        $this->index->refreshOne($this->source, $ref, $now);

        try {
            $this->reviews->saved($ref, $context, $now);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function deleted(Entry $entry, ?DateTimeImmutable $now = null): void
    {
        $now ??= Carbon::now()->toDateTimeImmutable();
        $ref = EntryChecks::ref($entry);

        $this->entries->forget($ref);
        $this->index->deleted($this->source, $ref, $now, StatamicLinkSource::targets($entry));

        foreach ($this->reviewStore->history($ref) as $review) {
            $this->reviewStore->delete($review->id);
        }
    }

    /**
     * A taxonomy term deleted: its rows forgotten in every site, and every
     * page that linked to its address checked again.
     */
    public function deletedTerm(Term $term, ?DateTimeImmutable $now = null): void
    {
        $now ??= Carbon::now()->toDateTimeImmutable();

        foreach (Site::all()->keys()->all() as $site) {
            $localized = $term->in((string) $site);
            $ref = new EntryRef(StatamicLinkSource::TAXONOMY.$term->taxonomyHandle(), (string) $term->id(), (string) $site);

            $this->entries->forget($ref);

            if ($localized !== null) {
                $this->index->deleted($this->source, $ref, $now, StatamicLinkSource::targets($localized));
            }
        }
    }

    /**
     * The daily pass, for each site. Returns how many entries were read.
     */
    public function daily(?DateTimeImmutable $now = null, bool $full = false, int|string|null $site = null): int
    {
        $now ??= Carbon::now()->toDateTimeImmutable();
        $state = $this->state();
        $read = 0;

        foreach ($site === null ? Site::all()->keys()->all() : [$site] as $handle) {
            $handle = (string) $handle;
            $last = self::time($state['sites'][$handle]['run'] ?? null);
            $lastFull = self::time($state['sites'][$handle]['full'] ?? null);
            $whole = $full || $last === null || $lastFull === null || $lastFull <= $now->modify('-'.self::FULL_EVERY.' days');

            if ($whole) {
                foreach ($this->source->all($handle) as $snapshot) {
                    $entry = \Statamic\Facades\Entry::find((string) $snapshot->ref->id);
                    $entry = $entry?->locale() === $handle ? $entry : $entry?->in($handle);

                    if ($entry) {
                        $row = $this->links->row($entry, IndexScope::Full);
                        $this->entries->put($snapshot->ref, $snapshot->title, $row->url, $snapshot->context, self::summary($entry), $row);
                    }
                }
            }

            $read += $this->index->refresh($this->source, $now, $whole ? null : $last, $handle, $whole);
            $this->linkPass($handle, $now, $whole);
            $state['sites'][$handle] = ['run' => $now->format(DATE_ATOM), 'full' => $whole ? $now->format(DATE_ATOM) : ($state['sites'][$handle]['full'] ?? null)];
        }

        $this->reviews->expire($now);
        $this->writeJson($this->statePath(), $state);

        return $read;
    }

    /**
     * The link rows' part of the daily pass (SEO layer §7.1): every other
     * routable group's pages, within the caps, a full pass weekly. A page
     * of one of Ghostwriter's collections that still has a link row (the
     * collection was added since) is indexed in full instead. Never stops
     * the rest of the pass.
     */
    private function linkPass(string $site, DateTimeImmutable $now, bool $full): void
    {
        try {
            $pass = $this->linkRows->pass($site, $now, $full);

            foreach ($pass['full'] as $ref) {
                $entry = $this->links->find($ref);

                if ($entry instanceof Entry) {
                    $this->saved($entry, $now);
                } else {
                    $this->entries->forget($ref);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The weekly check of links to other sites. Nothing is asked of any
     * other site unless a manager has turned it on.
     */
    public function links(ExternalLinkCheck $check, ?DateTimeImmutable $now = null): int
    {
        if (! $this->settings->checksExternalLinks()) {
            return 0;
        }

        $now ??= Carbon::now()->toDateTimeImmutable();
        $checked = 0;

        foreach (Site::all()->keys()->all() as $handle) {
            $checked += $check->run($this->rows, new RevisitOptions(externalLinks: true), $now, (string) $handle);
        }

        return $checked;
    }

    /** When the list was last brought up to date for a site; null before the first pass. */
    public function lastRun(int|string|null $site = null): ?DateTimeImmutable
    {
        return self::time($this->state()['sites'][(string) ($site ?? Site::default()->handle())]['run'] ?? null);
    }

    public static function ref(Entry $entry): EntryRef
    {
        return EntryChecks::ref($entry);
    }

    private static function summary(Entry $entry): string
    {
        foreach (['summary', 'excerpt', 'intro', 'meta_description', 'description'] as $handle) {
            $value = $entry->get($handle);

            if (is_string($value) && trim($value) !== '') {
                return trim(strip_tags($value));
            }
        }

        return '';
    }

    /**
     * @return array{sites?: array<string, array{run?: ?string, full?: ?string}>}
     */
    private function state(): array
    {
        $state = $this->readJson($this->statePath());

        return is_array($state) ? $state : [];
    }

    private function statePath(): string
    {
        return FileRevisitStore::path().'/state.json';
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
