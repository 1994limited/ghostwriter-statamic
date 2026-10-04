<?php

namespace NineteenNinetyFour\Ghostwriter\Suggest;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use NineteenNinetyFour\Ghostwriter\Core\Seo\Linkable;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkCandidates;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkPlan;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Storage\FileRevisitStore;
use NineteenNinetyFour\Ghostwriter\Storage\JsonFiles;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Throwable;

/**
 * Keeps the link index's link rows (SEO layer §7.1) in step with the site,
 * with no model:
 *
 * - saved(): one page of a group Ghostwriter doesn't write for saved: its
 *   row written again, or forgotten when it's a draft or has no address;
 * - mark(): a collection's route, a structure or a navigation changed: the
 *   group's rows are written again on the next daily pass;
 * - refresh(): the daily pass for a site (core's LinkPlan): missing, stale
 *   and marked rows written, key pages and the newest first, at most 5,000
 *   a run in chunks of 500; rows whose page is gone forgotten; every row
 *   once a week; the groups over their cap kept for the developer note.
 *
 * State is kept in `revisit/links.json`.
 */
class LinkRows
{
    use JsonFiles;

    /** Every group, for mark(). */
    public const ALL = '*';

    public function __construct(
        private StatamicLinkSource $source,
        private FileEntryIndex $index,
    ) {}

    /** A page of a group Ghostwriter doesn't write for was saved. */
    public function saved(Entry|Term $item): void
    {
        $ref = StatamicLinkSource::ref($item);

        if ($this->source->ghostwriters($ref->group)) {
            return;
        }

        if (! isset($this->source->groups((string) $ref->site)[$ref->group])) {
            $this->index->forget($ref);

            return;
        }

        $row = $this->source->row($item, IndexScope::Link);

        Linkable::keep($row) ? $this->index->putLinks([$row]) : $this->index->forget($ref);
    }

    /** A page by reference, read again: written, or forgotten when it's gone. */
    public function refresh(EntryRef $ref): void
    {
        $item = $this->source->find($ref);

        $item === null ? $this->index->forget($ref) : $this->saved($item);
    }

    /** A group's rows are written again on the next daily pass. */
    public function mark(string $group = self::ALL, ?DateTimeImmutable $now = null): void
    {
        $state = $this->state();
        $state['marks'][$group] = ($now ?? Carbon::now()->toDateTimeImmutable())->format(DATE_ATOM);
        $this->writeJson($this->statePath(), $state);
        $this->source->forget();
    }

    /**
     * The daily pass for a site: what LinkPlan says to write and forget.
     * Pages of Ghostwriter's own groups that still have link rows are
     * handed back, to be indexed in full.
     *
     * @return array{written: int, forgotten: int, pending: int, over: array<string, int>, full: list<EntryRef>}
     */
    public function pass(string $site, ?DateTimeImmutable $now = null, bool $full = false): array
    {
        $now ??= Carbon::now()->toDateTimeImmutable();
        $this->index->fresh();
        $state = $this->state();
        $siteState = is_array($state['sites'][$site] ?? null) ? $state['sites'][$site] : [];

        if ($full) {
            $siteState['full_from'] = $now->format(DATE_ATOM);
        }

        $groups = $this->source->groups($site);
        $linkGroups = array_filter($groups, fn (string $group) => ! $this->source->ghostwriters($group), ARRAY_FILTER_USE_KEY);
        $rows = [];
        $toFull = [];

        foreach ($this->index->meta($site) as $key => $meta) {
            $ours = $this->source->ghostwriters($meta['group']);

            if ($meta['scope'] === IndexScope::Link->value && $ours) {
                $toFull[] = $key;
            } elseif ($meta['scope'] === IndexScope::Link->value || ! $ours) {
                $rows[$key] = $meta;
            }
        }

        $marks = [];

        foreach (is_array($state['marks'] ?? null) ? $state['marks'] : [] as $group => $at) {
            if ($group === self::ALL) {
                foreach (array_keys($linkGroups) as $each) {
                    $marks[$each] = max($marks[$each] ?? '', (string) $at);
                }
            } else {
                $marks[(string) $group] = max($marks[(string) $group] ?? '', (string) $at);
            }
        }

        $plan = LinkPlan::make($this->source->pages($site, $linkGroups), $rows, $marks, is_string($siteState['full_from'] ?? null) ? $siteState['full_from'] : null);
        $written = 0;

        foreach ($plan->chunks(LinkCandidates::CHUNK) as $chunk) {
            $batch = [];

            foreach ($chunk as $key) {
                try {
                    $item = $this->source->find(self::refOf($key, $site));
                    $row = $item === null ? null : $this->source->row($item, IndexScope::Link);
                } catch (Throwable $exception) {
                    report($exception);
                    $row = null;
                }

                if ($row !== null && Linkable::keep($row)) {
                    $batch[] = $row;
                }
            }

            $this->index->putLinks($batch);
            $written += count($batch);
        }

        $this->index->forgetKeys($site, $plan->forget);
        $this->index->writeStems($site);

        $siteState['run'] = $now->format(DATE_ATOM);
        $siteState['over'] = $plan->over;
        $siteState['pending'] = $plan->pending;
        $state = $this->state();
        $state['sites'][$site] = $siteState;
        $this->writeJson($this->statePath(), $state);

        return [
            'written' => $written,
            'forgotten' => count($plan->forget),
            'pending' => $plan->pending,
            'over' => $plan->over,
            'full' => array_map(fn (string $key) => self::refOf($key, $site), array_slice($toFull, 0, LinkCandidates::CHUNK)),
        ];
    }

    /**
     * Groups over their cap, for the developer note: "Products has 48,000
     * entries; Ghostwriter links to the 5,000 most recently updated."
     *
     * @return list<array{site: string, group: string, label: string, count: int}>
     */
    public function over(): array
    {
        $notes = [];

        foreach (is_array($this->state()['sites'] ?? null) ? $this->state()['sites'] : [] as $site => $siteState) {
            $groups = $this->source->groups((string) $site);

            foreach (is_array($siteState['over'] ?? null) ? $siteState['over'] : [] as $group => $count) {
                $notes[] = ['site' => (string) $site, 'group' => (string) $group, 'label' => $groups[$group]['label'] ?? (string) $group, 'count' => (int) $count];
            }
        }

        return $notes;
    }

    /** "pages:abc@default" back to its reference. */
    public static function refOf(string $key, string $site): EntryRef
    {
        $suffix = '@'.$site;
        $bare = str_ends_with($key, $suffix) ? substr($key, 0, -strlen($suffix)) : $key;
        $colon = strpos($bare, ':', str_starts_with($bare, StatamicLinkSource::TAXONOMY) ? strlen(StatamicLinkSource::TAXONOMY) : 0);

        return new EntryRef(substr($bare, 0, (int) $colon), substr($bare, (int) $colon + 1), $site);
    }

    /**
     * @return array{sites?: array<string, array<string, mixed>>, marks?: array<string, string>}
     */
    private function state(): array
    {
        $state = $this->readJson($this->statePath());

        return is_array($state) ? $state : [];
    }

    private function statePath(): string
    {
        return FileRevisitStore::path().'/links.json';
    }
}
