<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

/**
 * Puts back the sets placed in an entry's Bard fields that the draft never
 * held. A draft is written in markdown, so of the sets in a Bard field only
 * pull quotes come through it; an image, a grid of figures or any other
 * furniture would be lost when the rewritten Bard replaced the entry's.
 *
 * Each such set goes back after the words it followed, wherever they are
 * now: a chosen layout can move a section into another block, and core's
 * Arrange carries no units for Bard sets, so the set follows its words.
 * Where those words were rewritten (or the set opened its field), it goes
 * back into its own field after as many nodes as it followed there, or at
 * the end where the draft is now shorter than that; and where its own field
 * is gone, at the end of the last rich text left in the same field.
 */
class KeptBardSets
{
    public function __construct(private BardToMarkdown $toMarkdown) {}

    /**
     * @param  array<string, mixed>  $merged  The draft merged over the entry.
     * @param  array<string, mixed>  $original  The entry as the form holds it.
     * @param  array<int, array<string, mixed>>  $specs
     * @return array<string, mixed>
     */
    public function keep(array $merged, array $original, array $specs): array
    {
        $present = [];
        self::setIds($merged, $present);

        $lost = [];
        $this->lost($original, $specs, '', $present, $lost);

        if ($lost === []) {
            return $merged;
        }

        /** @var list<array{at: string, nodes: array<int|string, mixed>}> $fields */
        $fields = [];
        $this->fields($merged, $specs, '', $fields);
        $where = $this->place($lost, $fields);

        foreach ($fields as $i => &$field) {
            if (isset($where[$i])) {
                $field['nodes'] = self::insert(array_values($field['nodes']), $where[$i]);
            }
        }

        unset($field);

        return $merged;
    }

    /**
     * The sets in the entry's Bard fields that the draft doesn't hold: where
     * each stood (its field, the words before it, how many nodes before it).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $specs
     * @param  array<string, true>  $present
     * @param  list<array{at: string, top: string, after: ?string, seen: int, node: array<string, mixed>}>  $lost
     */
    private function lost(array $data, array $specs, string $at, array $present, array &$lost): void
    {
        foreach ($specs as $spec) {
            $handle = $spec['handle'] ?? null;

            if ($handle === null || ! is_array($data[$handle] ?? null)) {
                continue;
            }

            $path = $at.'/'.$handle;

            if (($spec['type'] ?? null) === 'bard') {
                $seen = 0;
                $after = null;

                foreach ($data[$handle] as $node) {
                    if (! is_array($node)) {
                        continue;
                    }

                    if (($node['type'] ?? null) === 'set' && ! $this->toMarkdown->carries((array) ($node['attrs']['values'] ?? []))) {
                        if (! isset($present[$node['attrs']['id'] ?? null])) {
                            $lost[] = ['at' => $path, 'top' => self::top($path), 'after' => $after, 'seen' => $seen, 'node' => $node];
                        }

                        continue;
                    }

                    $seen++;
                    $after = self::words($node);
                }

                continue;
            }

            foreach ($this->children($data[$handle], $spec, $path) as [$child, $fields, $childAt]) {
                $this->lost($child, $fields, $childAt, $present, $lost);
            }
        }
    }

    /**
     * The merged entry's Bard fields, in order, each by reference.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $specs
     * @param  list<array{at: string, nodes: array<int|string, mixed>}>  $fields
     */
    private function fields(array &$data, array $specs, string $at, array &$fields): void
    {
        foreach ($specs as $spec) {
            $handle = $spec['handle'] ?? null;

            if ($handle === null || ! is_array($data[$handle] ?? null)) {
                continue;
            }

            $path = $at.'/'.$handle;

            if (($spec['type'] ?? null) === 'bard') {
                $fields[] = ['at' => $path, 'nodes' => &$data[$handle]];

                continue;
            }

            if (($spec['kind'] ?? null) === 'group') {
                $this->fields($data[$handle], $spec['fields'] ?? [], $path, $fields);

                continue;
            }

            foreach ($data[$handle] as $key => &$child) {
                if (! is_array($child)) {
                    continue;
                }

                [$childFields, $childAt] = $this->child($child, $key, $spec, $path);

                if ($childFields !== null) {
                    $this->fields($child, $childFields, $childAt, $fields);
                }
            }

            unset($child);
        }
    }

    /**
     * The blocks, rows or group under a field, with their fields and where
     * they are.
     *
     * @param  array<int|string, mixed>  $value
     * @param  array<string, mixed>  $spec
     * @return list<array{0: array<string, mixed>, 1: array<int, array<string, mixed>>, 2: string}>
     */
    private function children(array $value, array $spec, string $path): array
    {
        if (($spec['kind'] ?? null) === 'group') {
            return [[$value, $spec['fields'] ?? [], $path]];
        }

        $children = [];

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                [$fields, $at] = $this->child($child, $key, $spec, $path);

                if ($fields !== null) {
                    $children[] = [$child, $fields, $at];
                }
            }
        }

        return $children;
    }

    /**
     * A block is known by its ID (a merged block keeps the ID of the entry's
     * block it was matched to); a row by its position, as the merger
     * matches rows.
     *
     * @param  array<string, mixed>  $child
     * @param  array<string, mixed>  $spec
     * @return array{0: array<int, array<string, mixed>>|null, 1: string}
     */
    private function child(array $child, int|string $key, array $spec, string $path): array
    {
        return match ($spec['kind'] ?? null) {
            'blocks' => [$spec['sets'][$child['type'] ?? '']['fields'] ?? null, $path.'/'.($child['id'] ?? '#'.$key)],
            'rows' => [$spec['fields'] ?? null, $path.'/'.$key],
            default => [null, $path],
        };
    }

    /**
     * Where each lost set goes: by field, the sets to put after each node.
     *
     * @param  list<array{at: string, top: string, after: ?string, seen: int, node: array<string, mixed>}>  $lost
     * @param  list<array{at: string, nodes: array<int|string, mixed>}>  $fields
     * @return array<int, array<int, list<array<string, mixed>>>>
     */
    private function place(array $lost, array $fields): array
    {
        $words = [];
        $byPath = [];
        $lastIn = [];

        foreach ($fields as $i => $field) {
            $byPath[$field['at']] ??= $i;
            $lastIn[self::top($field['at'])] = $i;
            $words[$i] = [];

            foreach (array_values($field['nodes']) as $k => $node) {
                $words[$i][$k] = is_array($node) ? self::words($node) : null;
            }
        }

        $where = [];

        foreach ($lost as $set) {
            [$field, $after] = self::find($set['after'], $words) ?? match (true) {
                isset($byPath[$set['at']]) => [$byPath[$set['at']], $set['seen']],
                isset($lastIn[$set['top']]) => [$lastIn[$set['top']], PHP_INT_MAX],
                default => [null, null],
            };

            if ($field !== null) {
                $where[$field][$after][] = $set['node'];
            }
        }

        return $where;
    }

    /**
     * The first node, in any Bard field, holding exactly these words: the
     * field and how many nodes up to and including it.
     *
     * @param  array<int, array<int, ?string>>  $words
     * @return array{0: int, 1: int}|null
     */
    private static function find(?string $after, array $words): ?array
    {
        if ($after === null) {
            return null;
        }

        foreach ($words as $field => $nodes) {
            $k = array_search($after, $nodes, true);

            if ($k !== false) {
                return [$field, $k + 1];
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $nodes
     * @param  array<int, list<array<string, mixed>>>  $after  Sets by how many nodes they follow.
     * @return list<mixed>
     */
    private static function insert(array $nodes, array $after): array
    {
        ksort($after);
        $out = [...($after[0] ?? [])];
        unset($after[0]);

        foreach ($nodes as $i => $node) {
            $out[] = $node;
            $out = [...$out, ...($after[$i + 1] ?? [])];
            unset($after[$i + 1]);
        }

        // The draft is shorter now: what followed the missing nodes goes at the end.
        foreach ($after as $sets) {
            $out = [...$out, ...$sets];
        }

        return $out;
    }

    /**
     * A node's words, to know it by when it has moved; null for a set or
     * a node without words.
     *
     * @param  array<string, mixed>  $node
     */
    private static function words(array $node): ?string
    {
        if (($node['type'] ?? null) === 'set') {
            return null;
        }

        $text = [];
        array_walk_recursive($node, function ($value, $key) use (&$text) {
            if ($key === 'text' && is_string($value)) {
                $text[] = $value;
            }
        });

        $words = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $text)));

        return $words === '' ? null : $words;
    }

    /**
     * The IDs of every Bard set anywhere in the data.
     *
     * @param  array<int|string, mixed>  $data
     * @param  array<string, true>  $ids
     */
    private static function setIds(array $data, array &$ids): void
    {
        if (($data['type'] ?? null) === 'set' && is_string($data['attrs']['id'] ?? null)) {
            $ids[$data['attrs']['id']] = true;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                self::setIds($value, $ids);
            }
        }
    }

    /**
     * The top-level field a path is in.
     */
    private static function top(string $path): string
    {
        return explode('/', ltrim($path, '/'))[0];
    }
}
