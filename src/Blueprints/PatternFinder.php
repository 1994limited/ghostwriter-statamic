<?php

namespace NineteenNinetyFour\Ghostwriter\Blueprints;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ghostwriter\Drafts\EntrySimplifier;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;

/**
 * Studies the entries a collection already has to find out how its pages are
 * really put together, which the blueprint alone cannot say: a page builder
 * allows thirty blocks, but the articles only ever use six, in one order.
 *
 * It finds, for each blocks field, the usual sequence of blocks and how often
 * each is used; the values that are the same on nearly every entry, which are
 * house defaults rather than writing; and the newest entries as examples.
 */
class PatternFinder
{
    private const SAMPLE = 30;

    /** A value shared by this share of entries is a house default. */
    private const FIXED_SHARE = 0.8;

    /** Structured kinds that, when fixed, are copied whole and never rewritten. */
    public const COPIED_KINDS = ['rows', 'blocks', 'group', 'list', 'reference'];

    public function __construct(private EntrySimplifier $simplifier) {}

    /**
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $where  Field => value an entry must have (or contain, for lists).
     * @param  array<int, string>  $examples  Entry IDs to learn from instead.
     * @return array{entries: int, words: int, blocks: array<string, array{sequence: array<int, string>, usage: array<string, float>, fixed: array<string, array<string, mixed>>, used: array<string, array<int, string>>}>, fixed: array<string, mixed>, examples: array<int, array<string, mixed>>}
     */
    public function find(string $collection, array $schema, ?string $blueprint = null, array $where = [], array $examples = []): array
    {
        // Entries picked by hand are the whole evidence: a collection such as
        // Pages holds several kinds of page, and only a person knows which
        // ones a given type should be modelled on.
        $picked = collect($examples)->map(fn (string $id) => Entries::find($id))->filter()->values();

        if ($picked->isNotEmpty()) {
            return $this->patternFrom($picked, $schema);
        }

        $all = Entries::query()
            ->where('collection', $collection)
            ->where('published', true)
            ->get()
            ->when($blueprint, fn ($all) => $all->filter(fn (Entry $entry) => $entry->blueprint()?->handle() === $blueprint))
            ->sortByDesc(fn (Entry $entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->values();

        // A type can narrow the entries it learns from, such as the guides
        // within a collection of articles. Until some exist, the whole
        // collection is the best evidence there is.
        $matching = $where ? $all->filter(fn (Entry $entry) => $this->matches($entry, $where))->values() : $all;

        return $this->patternFrom(($matching->isEmpty() ? $all : $matching)->take(self::SAMPLE)->values(), $schema);
    }

    /**
     * @param  Collection<int, Entry>  $entries
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    private function patternFrom(Collection $entries, array $schema): array
    {

        $data = $entries->map(fn (Entry $entry) => $entry->data()->all());
        $simplified = $data->map(fn (array $entry) => $this->simplifier->simplify($entry, $schema));

        $blocks = [];

        foreach ($schema as $spec) {
            if ($spec['kind'] === 'blocks') {
                $blocks[$spec['handle']] = $this->blockPattern($data->pluck($spec['handle'])->filter()->all(), $spec['sets'] ?? []);
            }
        }

        $words = $simplified->map(fn (array $entry) => str_word_count(json_encode($entry) ?: ''))->sort()->values();

        return [
            'entries' => $entries->count(),
            'words' => $words->isEmpty() ? 0 : (int) $words->get(intdiv($words->count(), 2)),
            'blocks' => $blocks,
            'fixed' => $this->fixedValues($data->all(), array_merge(['title', 'slug', 'date', 'id', 'blueprint', 'published', 'updated_at', 'updated_by'], array_keys($blocks), array_column(array_filter($schema, [SchemaReader::class, 'writable']), 'handle'))),
            'examples' => $simplified->take(2)->map(fn (array $example) => $this->withoutDefaults($example, $schema, $blocks))->values()->all(),
            'filled' => $this->fillRates($data->all(), $schema),
            'house' => (new HouseStyle)->learn($data->values()->all(), $schema, $entries->map(fn (Entry $entry) => (string) $entry->id())->values()->all()),
        ];
    }

    /**
     * How often each field holds something: on the entry, keyed by handle,
     * and on blocks, keyed "blockType.handle", at any depth. It is how an
     * image that every page has is told from a background few pages use.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<string, float>
     */
    private function fillRates(array $items, array $schema): array
    {
        $counts = [];
        $totals = [];

        $walk = function (array $items, array $specs, string $prefix) use (&$walk, &$counts, &$totals): void {
            foreach ($items as $item) {
                if (! is_array($item) || ($item['enabled'] ?? true) === false) {
                    continue;
                }

                foreach ($specs as $spec) {
                    $key = $prefix.$spec['handle'];
                    $value = $item[$spec['handle']] ?? null;

                    $totals[$key] = ($totals[$key] ?? 0) + 1;

                    if ($value !== null && $value !== '' && $value !== []) {
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }

                    if ($spec['kind'] === 'blocks' && is_array($value)) {
                        foreach ($value as $block) {
                            $set = is_array($block) ? ($spec['sets'][$block['type'] ?? ''] ?? null) : null;

                            if ($set) {
                                $walk([$block], $set['fields'], $block['type'].'.');
                            }
                        }
                    }
                }
            }
        };

        $walk($items, $schema, '');

        $rates = [];

        foreach ($totals as $key => $total) {
            $rates[$key] = round(($counts[$key] ?? 0) / $total, 2);
        }

        return $rates;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function matches(Entry $entry, array $where): bool
    {
        foreach ($where as $field => $expected) {
            if (! in_array($expected, (array) $entry->get($field), false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $fields  One blocks field's value from each entry.
     * @param  array<string, array<string, mixed>>  $available  The field's sets, as read from the blueprint.
     * @return array{sequence: array<int, string>, usage: array<string, float>, fixed: array<string, array<string, mixed>>, used: array<string, array<int, string>>, boilerplate: array<int, string>}
     */
    private function blockPattern(array $fields, array $available = []): array
    {
        $sequences = [];
        $byType = [];
        $entriesUsing = [];

        foreach ($fields as $sets) {
            $sequence = [];

            foreach ((array) $sets as $set) {
                if (! is_array($set) || ! isset($set['type']) || ($set['enabled'] ?? true) === false) {
                    continue;
                }

                $sequence[] = $set['type'];
                $byType[$set['type']][] = $set;
            }

            $sequences[] = $sequence;

            foreach (array_unique($sequence) as $type) {
                $entriesUsing[$type] = ($entriesUsing[$type] ?? 0) + 1;
            }
        }

        $total = max(count($fields), 1);

        arsort($entriesUsing);

        $fixed = array_filter(array_map(fn (array $items) => $this->fixedValues($items, ['id', 'type', 'enabled'], reused: true), $byType));
        $used = array_map(fn (array $items) => $this->usedKeys($items), $byType);

        return [
            'sequence' => $this->commonest($sequences),
            'usage' => array_map(fn (int $count) => round($count / $total, 2), $entriesUsing),
            'fixed' => $fixed,
            'used' => $used,
            'boilerplate' => array_values(array_filter(
                array_keys($byType),
                fn (string $type) => count($byType[$type]) >= 2 && $this->isBoilerplate($available[$type]['fields'] ?? [], $fixed[$type] ?? [], $used[$type]),
            )),
        ];
    }

    /**
     * A block is boilerplate when nothing in it is written afresh each time:
     * every field of content that gets used holds a house default. Process
     * steps and testimonials are typical. Such a block is copied, not
     * written. A setting that varies, such as a background, does not make a
     * block's content any less fixed.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $fixed
     * @param  array<int, string>  $used
     */
    private function isBoilerplate(array $fields, array $fixed, array $used): bool
    {
        foreach ($fields as $field) {
            // Settings and references (images, links) are not writing.
            $isContent = SchemaReader::writable($field) && ! in_array($field['kind'], SchemaReader::SETTING_KINDS, true);

            if ($isContent && in_array($field['handle'], $used, true) && ! array_key_exists($field['handle'], $fixed)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The order of blocks used most often. Entries are newest first, so a tie
     * goes to the most recent way of doing it.
     *
     * @param  array<int, array<int, string>>  $sequences
     * @return array<int, string>
     */
    private function commonest(array $sequences): array
    {
        $counts = [];

        foreach ($sequences as $sequence) {
            $key = implode('>', $sequence);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        if ($counts === []) {
            return [];
        }

        $best = array_search(max($counts), $counts, true);

        return $best === '' ? [] : explode('>', (string) $best);
    }

    /**
     * Keys whose value is identical on nearly every item.
     *
     * @param  array<int, array<string, mixed>>  $items
     *                                                   With `reused`, structured content (rows, groups, lists) also counts when
     *                                                   no item has a version of its own: a page builder's process steps might
     *                                                   come in a "website" and an "app" wording, each pasted onto several
     *                                                   pages. Nobody writes those afresh, so the commonest version is used.
     * @param  array<int, string>  $ignore
     * @return array<string, mixed>
     */
    private function fixedValues(array $items, array $ignore, bool $reused = false): array
    {
        // A single item proves nothing about a house default.
        if (count($items) < 2) {
            return [];
        }

        $seen = [];
        $samples = [];

        foreach ($items as $item) {
            foreach ($item as $key => $value) {
                if (in_array($key, $ignore, true)) {
                    continue;
                }

                // Rows and nested sets carry random IDs, so two copies of the
                // same content only compare equal once those are set aside.
                $encoded = json_encode($this->withoutIds($value));

                $seen[$key][$encoded] = ($seen[$key][$encoded] ?? 0) + 1;
                $samples[$key][$encoded] ??= $value;
            }
        }

        $fixed = [];

        foreach ($seen as $key => $values) {
            arsort($values);
            $encoded = array_key_first($values);

            $sample = $samples[$key][$encoded];
            $neverUnique = $reused && is_array($sample) && $sample !== [] && min($values) >= 2 && array_sum($values) === count($items);

            if ($values[$encoded] / count($items) >= self::FIXED_SHARE || $neverUnique) {
                $fixed[$key] = $sample;
            }
        }

        return $fixed;
    }

    /**
     * The keys that hold something on at least one item. A field nobody has
     * ever filled in is not part of how this collection is written.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, string>
     */
    private function usedKeys(array $items): array
    {
        $used = [];

        foreach ($items as $item) {
            foreach ($item as $key => $value) {
                if ($value !== null && $value !== '' && $value !== [] && ! in_array($key, ['id', 'type', 'enabled'], true)) {
                    $used[$key] = true;
                }
            }
        }

        return array_keys($used);
    }

    /**
     * Examples are shown without the settings that are the same everywhere,
     * so what is left is the writing.
     *
     * @param  array<string, mixed>  $example
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $blocks
     * @return array<string, mixed>
     */
    private function withoutDefaults(array $example, array $schema, array $blocks): array
    {
        foreach ($schema as $spec) {
            if ($spec['kind'] !== 'blocks' || ! isset($example[$spec['handle']])) {
                continue;
            }

            $pattern = $blocks[$spec['handle']];

            $example[$spec['handle']] = array_map(function (array $block) use ($spec, $pattern) {
                // A boilerplate block is copied when the entry is built, so
                // the writer only needs to see where it goes.
                if (in_array($block['type'], $pattern['boilerplate'], true)) {
                    return ['type' => $block['type']];
                }

                $kinds = array_column($spec['sets'][$block['type']]['fields'] ?? [], 'kind', 'handle');

                foreach ($pattern['fixed'][$block['type']] ?? [] as $key => $value) {
                    if (! array_key_exists($key, $block)) {
                        continue;
                    }

                    $kind = $kinds[$key] ?? null;
                    $isSetting = in_array($kind, SchemaReader::SETTING_KINDS, true) && $block[$key] === $value;

                    if ($isSetting || in_array($kind, self::COPIED_KINDS, true)) {
                        unset($block[$key]);
                    }
                }

                return $block;
            }, $example[$spec['handle']]);
        }

        return $example;
    }

    /**
     * @return mixed The value with every `id` key removed, at any depth.
     */
    private function withoutIds(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        unset($value['id']);

        return array_map(fn ($item) => $this->withoutIds($item), $value);
    }
}
