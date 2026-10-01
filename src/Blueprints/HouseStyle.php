<?php

namespace NineteenNinetyFour\Ghostwriter\Blueprints;

/**
 * What the model entries agree on, place by place, that the pattern finder's
 * "same on every block of this type" cannot see:
 *
 *   positions  settings and links by where a block sits. The first spacer on
 *              a page is 45/65, the last 60/100; the first two breadcrumbs
 *              are always Home and Studio. Agreed values are copied into the
 *              same place in a new entry.
 *   sequences  how many items, of which types, a replicator nested in a
 *              block usually holds, so three breadcrumbs are made where
 *              pages have three.
 *   markup     how rich text is dressed in each place. A hero heading that
 *              is always centred and bold gets the same attributes and marks;
 *              the writer only writes words.
 *
 * A link from a page to itself, such as a breadcrumb's last step, is
 * recognised as one: it is stored as "this page" and becomes a link to the
 * new entry, with its title. Where two or more model pages link to
 * themselves in the same place and none links anywhere else, the new page
 * does too, however many leave it empty.
 *
 * A link the pages usually have, or that is required, but that nothing
 * settles, points at https://example.com so the page works and the gap is
 * plain to see; it is listed with the places still to fill.
 *
 * Places are paths through the page builder: "page_builder/spacer#1" is the
 * second spacer, "page_builder/hero#0/children/text#0" the text inside the
 * first hero. Markup goes by the path without the counts.
 */
class HouseStyle
{
    /**
     * Share of the model entries that must agree for a link or other
     * structured value to be copied: a wrong link is worse than none.
     */
    private const AGREED = 0.8;

    /**
     * For a setting (a number, a choice, a word) or a piece of markup, the
     * commonest is taken once more than half agree: a spacer needs some
     * height, and the usual one is the best guess.
     */
    private const MAJORITY = 0.5;

    private const BOOKKEEPING = ['id', 'type', 'enabled'];

    /** Bard nodes whose dressing is learned. */
    private const TEXT_NODES = ['heading', 'paragraph', 'blockquote', 'bulletList', 'orderedList'];

    /** Stands for the entry itself, and for its title, in a learned value. */
    public const SELF = '@self';

    public const TITLE = '@title';

    /** Where a link that should be there but cannot be decided points. */
    public const PLACEHOLDER_URL = 'https://example.com';

    /** What it says, where a block has a text field for the link's words. */
    public const PLACEHOLDER_TEXT = 'Link to choose';

    /** Fieldtypes that hold where a link goes. */
    private const LINK_TYPES = ['link', 'entries'];

    /**
     * @param  array<int, array<string, mixed>>  $entries  Entry data as stored.
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<int, string|null>  $ids  Each entry's ID, in the same order, to find links to itself.
     * @return array{positions: array<string, array<string, mixed>>, sequences: array<string, array<int, string>>, markup: array<string, array<string, array<string, mixed>>>, links: array<string, float>}
     */
    public function learn(array $entries, array $schema, array $ids = []): array
    {
        $items = [];
        $lists = [];
        $rich = [];
        $links = [];

        foreach ($entries as $i => $entry) {
            $own = [];
            $this->collect($entry, $schema, '', '', $own, $lists, $rich, $links);

            // A link to the entry itself reads the same on every entry once
            // it is written as "this page".
            foreach ($own as $path => $found) {
                foreach ($found as $item) {
                    $items[$path][] = isset($ids[$i]) ? $this->generalise($item, (string) $ids[$i], (string) ($entry['title'] ?? '')) : $item;
                }
            }
        }

        $positions = [];

        foreach ($items as $path => $found) {
            if (count($found) >= 2 && ($agreed = $this->agreed($found, count($entries))) !== []) {
                $positions[$path] = $agreed;
            }
        }

        $sequences = [];

        foreach ($lists as $path => $found) {
            $counts = [];

            foreach ($found as $sequence) {
                $counts[implode('>', $sequence)] = ($counts[implode('>', $sequence)] ?? 0) + 1;
            }

            arsort($counts);
            $best = (string) array_key_first($counts);

            if (count($found) >= 2 && $counts[$best] / count($found) >= self::AGREED && $best !== '') {
                $sequences[$path] = explode('>', $best);
            }
        }

        $markup = [];

        foreach ($rich as $path => $samples) {
            if (count($samples) >= 2 && ($shapes = $this->shapes($samples)) !== []) {
                $markup[$path] = $shapes;
            }
        }

        // How often each kind of block has its link set, wherever it sits.
        $linked = array_map(fn (array $found) => array_sum($found) / count($found), $links);

        return ['positions' => $positions, 'sequences' => $sequences, 'markup' => $markup, 'links' => $linked];
    }

    /**
     * Fill a new entry's data from the house style: agreed values where the
     * draft left a place empty, nested items where the draft has none, and
     * the house markup around the writer's rich text.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $style  From learn().
     * @param  array<int, string>  $toFill  Places still for a person, by label.
     * @param  array{id: ?string, title: string}  $self  The new entry, for links to itself.
     * @return array<string, mixed>
     */
    public function apply(array $data, array $schema, array $style, array &$toFill = [], array $self = ['id' => null, 'title' => ''], string $path = '', string $shape = '', string $label = ''): array
    {
        foreach ($schema as $spec) {
            $handle = $spec['handle'];
            $value = $data[$handle] ?? null;

            if ($spec['kind'] === 'richtext' && is_array($value) && $value !== []) {
                $data[$handle] = $this->dress($value, $style['markup']["{$shape}.{$handle}"] ?? []);

                continue;
            }

            if ($spec['kind'] !== 'blocks') {
                continue;
            }

            $base = $path === '' ? $handle : "{$path}/{$handle}";
            $shapeBase = $shape === '' ? $handle : "{$shape}/{$handle}";

            // A replicator the draft left empty, such as breadcrumbs, made as
            // the model entries have it.
            if ((! is_array($value) || $value === []) && isset($style['sequences'][$base])) {
                $value = array_map(fn (string $type) => ['id' => bin2hex(random_bytes(4)), 'type' => $type, 'enabled' => true], $style['sequences'][$base]);
            }

            if (! is_array($value)) {
                continue;
            }

            $seen = [];

            foreach ($value as $i => $block) {
                $type = is_array($block) ? ($block['type'] ?? null) : null;
                $set = $type !== null ? ($spec['sets'][$type] ?? null) : null;

                if ($set === null) {
                    continue;
                }

                $n = $seen[$type] = ($seen[$type] ?? -1) + 1;
                $here = "{$base}/{$type}#{$n}";
                $name = ($label === '' ? '' : "{$label}: ").$set['display'].(count(array_filter($value, fn ($other) => ($other['type'] ?? null) === $type)) > 1 ? ' '.($n + 1) : '');

                foreach ($style['positions'][$here] ?? [] as $key => $agreed) {
                    if (! isset($block[$key]) || $block[$key] === '' || $block[$key] === []) {
                        $filled = $this->specific($this->withoutIds($agreed), $self);

                        // A link to "this page" waits until there is a page to link to.
                        if (! $this->mentionsSelf($filled)) {
                            $block[$key] = $filled;
                        }
                    }
                }

                foreach ($set['fields'] as $field) {
                    // A link to "this page" is coming once the page exists.
                    $waiting = $this->mentionsSelf($style['positions'][$here][$field['handle']] ?? null);

                    if (! in_array($field['type'], self::LINK_TYPES, true) || ! empty($block[$field['handle']]) || $waiting) {
                        continue;
                    }

                    // A link the block should have that nothing settles goes
                    // to example.com for now, so the page works and the gap shows.
                    $expected = ($field['required'] ?? false) || ($style['links']["{$shapeBase}/{$type}.{$field['handle']}"] ?? 0) >= self::MAJORITY;

                    if ($expected && $field['type'] === 'link') {
                        $block[$field['handle']] = self::PLACEHOLDER_URL;

                        foreach ($this->textFor($field['handle'], $set['fields']) as $text) {
                            $block[$text] ??= self::PLACEHOLDER_TEXT;
                        }

                        $toFill[] = "{$name} (links to example.com for now)";
                    } elseif ($expected) {
                        // Entries to pick cannot be stood in for; name the gap.
                        $toFill[] = "{$name}: ".($field['display'] ?: $field['handle']);
                    }
                }

                $value[$i] = $this->apply($block, $set['fields'], $style, $toFill, $self, $here, "{$shapeBase}/{$type}", $name);
            }

            $data[$handle] = $value;
        }

        return $data;
    }

    /**
     * Once the entry exists: the links to "this page" the house style held
     * back, now pointing at it.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    public function linkToSelf(array $data, array $schema, array $style, string $id, string $title, string $path = ''): array
    {
        foreach ($schema as $spec) {
            if ($spec['kind'] !== 'blocks' || ! is_array($data[$spec['handle']] ?? null)) {
                continue;
            }

            $base = $path === '' ? $spec['handle'] : "{$path}/{$spec['handle']}";
            $seen = [];

            foreach ($data[$spec['handle']] as $i => $block) {
                $type = is_array($block) ? ($block['type'] ?? null) : null;
                $set = $type !== null ? ($spec['sets'][$type] ?? null) : null;

                if ($set === null) {
                    continue;
                }

                $n = $seen[$type] = ($seen[$type] ?? -1) + 1;
                $here = "{$base}/{$type}#{$n}";

                foreach ($style['positions'][$here] ?? [] as $key => $agreed) {
                    if ((! isset($block[$key]) || $block[$key] === '' || $block[$key] === []) && $this->mentionsSelf($agreed)) {
                        $block[$key] = $this->specific($this->withoutIds($agreed), ['id' => $id, 'title' => $title]);
                    }
                }

                $data[$spec['handle']][$i] = $this->linkToSelf($block, $set['fields'], $style, $id, $title, $here);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $items
     * @param  array<string, array<int, array<int, string>>>  $lists
     * @param  array<string, array<int, array<int, mixed>>>  $rich
     * @param  array<string, array<int, int>>  $links  Per kind of block and link field, 1 where set and 0 where not.
     */
    private function collect(array $data, array $schema, string $path, string $shape, array &$items, array &$lists, array &$rich, array &$links): void
    {
        foreach ($schema as $spec) {
            $handle = $spec['handle'];
            $value = $data[$handle] ?? null;

            if ($spec['kind'] === 'richtext' && is_array($value) && $value !== []) {
                $rich["{$shape}.{$handle}"][] = $value;

                continue;
            }

            if ($spec['kind'] !== 'blocks' || ! is_array($value)) {
                continue;
            }

            $base = $path === '' ? $handle : "{$path}/{$handle}";
            $shapeBase = $shape === '' ? $handle : "{$shape}/{$handle}";
            $seen = [];
            $sequence = [];

            foreach ($value as $block) {
                if (! is_array($block) || ! isset($block['type']) || ($block['enabled'] ?? true) === false) {
                    continue;
                }

                $set = $spec['sets'][$block['type']] ?? null;

                if ($set === null) {
                    continue;
                }

                $n = $seen[$block['type']] = ($seen[$block['type']] ?? -1) + 1;
                $here = "{$base}/{$block['type']}#{$n}";
                $sequence[] = $block['type'];

                // Only the block's own plain values; replicators and rich
                // text inside it are taken place by place, below.
                $own = [];

                foreach ($set['fields'] as $field) {
                    if (! in_array($field['kind'], ['blocks', 'richtext'], true) && array_key_exists($field['handle'], $block)) {
                        $own[$field['handle']] = $block[$field['handle']];
                    }

                    if (in_array($field['type'], self::LINK_TYPES, true)) {
                        $links["{$shapeBase}/{$block['type']}.{$field['handle']}"][] = $this->hasLink($block[$field['handle']] ?? null) ? 1 : 0;
                    }
                }

                $items[$here][] = $own;

                $this->collect($block, $set['fields'], $here, "{$shapeBase}/{$block['type']}", $items, $lists, $rich, $links);
            }

            // Only replicators inside blocks: the page builder's own order is
            // the pattern finder's to say.
            if ($path !== '') {
                $lists[$base][] = $sequence;
            }
        }
    }

    /**
     * Values that most entries agree on in one place. Empty is not a value.
     *
     * @param  array<int, array<string, mixed>>  $found
     * @return array<string, mixed>
     */
    private function agreed(array $found, int $entries): array
    {
        $seen = [];
        $samples = [];

        foreach ($found as $item) {
            foreach ($item as $key => $value) {
                if (in_array($key, self::BOOKKEEPING, true) || $value === null || $value === '' || $value === []) {
                    continue;
                }

                $encoded = (string) json_encode($this->withoutIds($value));
                $seen[$key][$encoded] = ($seen[$key][$encoded] ?? 0) + 1;
                $samples[$key][$encoded] ??= $value;
            }
        }

        $agreed = [];

        foreach ($seen as $key => $values) {
            arsort($values);
            $encoded = array_key_first($values);
            $sample = $samples[$key][$encoded];

            // Counted against every model entry, so a value only some pages
            // have is not taken for the house's.
            $share = $values[$encoded] / max($entries, count($found));
            $structured = is_array($sample) || $this->looksLikeLink($sample);
            $needed = $structured ? self::AGREED : self::MAJORITY;

            // A link is copied when nearly every entry has it, or when more
            // than half do and none has anything different: a page that
            // leaves it empty is not a page that disagrees.
            $unopposed = count($values) === 1 && $share > self::MAJORITY;

            // A link to the page itself is copied when two or more pages
            // have one there and every link there is to the page itself,
            // whatever each calls it.
            $selfLinks = array_filter(array_keys($values), fn (string $value) => $this->mentionsSelf(json_decode($value, true)));
            $toSelf = count($selfLinks) === count($values) && array_sum(array_intersect_key($values, array_flip($selfLinks))) >= 2;

            if ($share > $needed || $toSelf || ($structured && ($share >= $needed || $unopposed))) {
                $agreed[$key] = $sample;
            }
        }

        return $agreed;
    }

    /**
     * How each kind of text node is dressed in these Bard samples, where
     * they agree: its attributes, and the marks on all of its words.
     *
     * @param  array<int, array<int, mixed>>  $samples
     * @return array<string, array<string, mixed>>
     */
    private function shapes(array $samples): array
    {
        $byType = [];

        foreach ($samples as $nodes) {
            $seenHere = [];

            foreach ($nodes as $node) {
                $kind = is_array($node) ? $this->kindOf($node) : null;

                // One vote per sample per kind of node: the first such node.
                if ($kind === null || isset($seenHere[$kind])) {
                    continue;
                }

                $seenHere[$kind] = true;
                $shape = $this->shapeOf($node);
                $byType[$kind][(string) json_encode($shape)][] = $shape;
            }
        }

        $shapes = [];

        foreach ($byType as $kind => $variants) {
            uasort($variants, fn (array $a, array $b) => count($b) <=> count($a));
            $best = reset($variants);
            $total = array_sum(array_map('count', $variants));

            // A plain node is the default and needs nothing.
            if ($total >= 2 && count($best) / $total > self::MAJORITY && ($best[0]['attrs'] !== [] || $best[0]['marks'] !== [])) {
                $shapes[$kind] = $best[0];
            }
        }

        return $shapes;
    }

    /**
     * "heading2", "paragraph": a heading's level is part of what it is, not
     * how it is dressed.
     *
     * @param  array<string, mixed>  $node
     */
    private function kindOf(array $node): ?string
    {
        $type = (string) ($node['type'] ?? '');

        if (! in_array($type, self::TEXT_NODES, true)) {
            return null;
        }

        return $type === 'heading' ? 'heading'.(int) ($node['attrs']['level'] ?? 1) : $type;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{attrs: array<string, mixed>, marks: array<int, array<string, mixed>>}
     */
    private function shapeOf(array $node): array
    {
        $attrs = array_filter((array) ($node['attrs'] ?? []), fn ($value, $key) => $key !== 'level' && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH);
        ksort($attrs);

        // Marks every piece of text in the node carries.
        $marks = null;

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (! is_array($child) || ($child['type'] ?? null) !== 'text') {
                continue;
            }

            $own = array_map(fn ($mark) => json_encode($mark), (array) ($child['marks'] ?? []));
            $marks = $marks === null ? $own : array_values(array_intersect($marks, $own));
        }

        return ['attrs' => $attrs, 'marks' => array_map(fn (string $mark) => json_decode($mark, true), $marks ?? [])];
    }

    /**
     * Put the house dressing on the writer's plain nodes.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<string, array<string, mixed>>  $shapes
     * @return array<int, mixed>
     */
    private function dress(array $nodes, array $shapes): array
    {
        if ($shapes === []) {
            return $nodes;
        }

        foreach ($nodes as $i => $node) {
            $kind = is_array($node) ? $this->kindOf($node) : null;
            $shape = $kind !== null ? ($shapes[$kind] ?? null) : null;

            if ($shape === null) {
                continue;
            }

            // Only plain nodes: anything the writer dressed is left as it is.
            $plain = $this->shapeOf($node);

            if ($plain['attrs'] !== [] || $plain['marks'] !== []) {
                continue;
            }

            $node['attrs'] = array_merge((array) ($node['attrs'] ?? []), $shape['attrs']);

            if ($shape['marks'] !== []) {
                foreach ((array) ($node['content'] ?? []) as $j => $child) {
                    if (is_array($child) && ($child['type'] ?? null) === 'text') {
                        $node['content'][$j]['marks'] = $shape['marks'];
                    }
                }
            }

            $nodes[$i] = $node;
        }

        return $nodes;
    }

    /**
     * Text fields that hold a link's words, by name: `link_text` for `link`,
     * `button_text` for `button_link`, `text` for `link`.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, string>
     */
    private function textFor(string $link, array $fields): array
    {
        $stem = preg_replace('/_?link$/', '', $link) ?? '';
        $wanted = array_unique(array_filter([$link.'_text', $stem !== '' ? $stem.'_text' : null, $stem !== '' ? $stem.'_label' : null, $link.'_label', $stem === '' ? 'text' : null]));

        return array_values(array_filter(array_column($fields, 'handle'), fn (string $handle) => in_array($handle, $wanted, true)));
    }

    /**
     * A link to its own entry, written as "this page", and its own title as
     * "the title".
     */
    private function generalise(mixed $value, string $id, string $title): mixed
    {
        if (! is_array($value)) {
            return $value === "entry::{$id}" ? self::SELF : ($title !== '' && $value === $title ? self::TITLE : $value);
        }

        if ($value === [$id]) {
            return self::SELF;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->generalise($item, $id, $title);
        }

        return $value;
    }

    /**
     * "This page" made into the new entry and its title.
     *
     * @param  array{id: ?string, title: string}  $self
     */
    private function specific(mixed $value, array $self): mixed
    {
        if ($value === self::SELF) {
            return $self['id'] !== null ? "entry::{$self['id']}" : self::SELF;
        }

        if ($value === self::TITLE) {
            return $self['title'];
        }

        return is_array($value) ? array_map(fn ($item) => $this->specific($item, $self), $value) : $value;
    }

    private function hasLink(mixed $value): bool
    {
        if (is_array($value)) {
            return array_filter($value, fn ($item) => $this->hasLink($item)) !== [];
        }

        return is_string($value) && trim($value) !== '';
    }

    private function looksLikeLink(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('#^(entry::|asset::|term::|https?://|mailto:|tel:)#', $value);
    }

    private function mentionsSelf(mixed $value): bool
    {
        if ($value === self::SELF) {
            return true;
        }

        return is_array($value) && array_filter($value, fn ($item) => $this->mentionsSelf($item)) !== [];
    }

    private function withoutIds(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        unset($value['id']);

        return array_map(fn ($item) => $this->withoutIds($item), $value);
    }
}
