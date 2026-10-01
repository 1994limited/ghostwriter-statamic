<?php

namespace NineteenNinetyFour\Ghostwriter\Blueprints;

use Illuminate\Support\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Structures\Page;

/**
 * Finds the kinds of entry a collection already holds by grouping entries
 * that are built the same way. A Pages collection might turn out to hold
 * landing pages, service pages and a few one-offs; nobody has to say so.
 *
 * It is what makes the options different on every site: they come from that
 * site's own content, with no configuration and no model call.
 */
class KindFinder
{
    /** Two entries are the same kind when this share of their blocks match. */
    private const SIMILARITY = 0.6;

    /** Example entries offered per kind. */
    private const EXAMPLES = 6;

    /**
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<int, array{label: string, count: int, examples: array<int, string>, titles: array<int, string>, blocks: array<int, string>}>
     */
    public function find(string $collection, array $schema, ?string $blueprint = null): array
    {
        $field = collect($schema)->firstWhere('kind', 'blocks')['handle'] ?? null;

        // Without a page builder every entry is built the same way.
        if ($field === null) {
            return [];
        }

        $entries = Entries::query()
            ->where('collection', $collection)
            ->where('published', true)
            ->get()
            ->when($blueprint, fn ($all) => $all->filter(fn (Entry $entry) => $entry->blueprint()?->handle() === $blueprint))
            ->sortByDesc(fn (Entry $entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->values();

        $groups = [];

        foreach ($entries as $entry) {
            $blocks = $this->blocks($entry, $field);

            if ($blocks === []) {
                continue;
            }

            foreach ($groups as &$group) {
                if ($this->similarity($blocks, $group['blocks']) >= self::SIMILARITY) {
                    $group['entries'][] = $entry;

                    continue 2;
                }
            }

            unset($group);

            $groups[] = ['blocks' => $blocks, 'entries' => [$entry]];
        }

        unset($group);

        $kinds = collect($groups)
            ->filter(fn (array $group) => count($group['entries']) >= 2)
            ->sortByDesc(fn (array $group) => count($group['entries']))
            ->values();

        // One group covering nearly everything is just "the collection".
        if ($kinds->count() < 2 && $kinds->sum(fn (array $group) => count($group['entries'])) >= $entries->count() - 1) {
            return [];
        }

        return $kinds->map(function (array $group) {
            $members = collect($group['entries']);

            return [
                'label' => $this->label($members),
                'count' => $members->count(),
                'examples' => $members->take(self::EXAMPLES)->map->id()->values()->all(),
                'titles' => $members->map(fn (Entry $entry) => (string) $entry->get('title'))->values()->all(),
                'blocks' => $group['blocks'],
            ];
        })->all();
    }

    /**
     * @return array<int, string>
     */
    private function blocks(Entry $entry, string $field): array
    {
        $blocks = [];

        foreach ((array) $entry->get($field) as $set) {
            if (is_array($set) && isset($set['type']) && ($set['enabled'] ?? true) !== false) {
                $blocks[] = (string) $set['type'];
            }
        }

        return $blocks;
    }

    /**
     * Share of block types the two have in common.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function similarity(array $a, array $b): float
    {
        $a = array_unique($a);
        $b = array_unique($b);

        return count(array_intersect($a, $b)) / max(count(array_unique([...$a, ...$b])), 1);
    }

    /**
     * Named after the section the entries sit under when they share one,
     * otherwise after the entries themselves.
     *
     * @param  Collection<int, Entry>  $entries
     */
    private function label(Collection $entries): string
    {
        $parents = $entries->map(fn (Entry $entry) => $entry->parent())->unique(fn (?Page $parent) => $parent?->id());
        $parent = $parents->first();

        // A shared parent names the group, unless it is the site's home page,
        // which every top-level page sits under.
        if ($parents->count() === 1 && $parent && ! $parent->isRoot()) {
            return 'Like the pages under '.$parent->title();
        }

        $titles = $entries->take(2)->map(fn (Entry $entry) => (string) $entry->get('title'));
        $more = $entries->count() - 2;

        return 'Like '.($more > 0 ? $titles->join(', ').' and '.$more.' more' : $titles->join(' and '));
    }
}
