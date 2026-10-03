<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

/**
 * Puts back the sets placed in an entry's Bard fields that the draft never
 * held. A draft is written in markdown, so of the sets in a Bard field only
 * pull quotes come through it; an image, a grid of figures or any other
 * furniture would be lost when the rewritten Bard replaced the entry's.
 *
 * Each such set goes back where it stood among the writing: after as many
 * nodes as it followed in the entry, or at the end where the draft is now
 * shorter than that.
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
        foreach ($specs as $spec) {
            $handle = $spec['handle'] ?? null;

            if ($handle === null || ! is_array($merged[$handle] ?? null) || ! is_array($original[$handle] ?? null)) {
                continue;
            }

            $merged[$handle] = match (true) {
                ($spec['type'] ?? null) === 'bard' => $this->nodes($merged[$handle], $original[$handle]),
                $spec['kind'] === 'blocks' => $this->blocks($merged[$handle], $original[$handle], $spec),
                $spec['kind'] === 'rows' => $this->rows($merged[$handle], $original[$handle], $spec['fields'] ?? []),
                $spec['kind'] === 'group' => $this->keep($merged[$handle], $original[$handle], $spec['fields'] ?? []),
                default => $merged[$handle],
            };
        }

        return $merged;
    }

    /**
     * A merged block kept the ID of the entry's block it was matched to.
     *
     * @param  array<int|string, mixed>  $merged
     * @param  array<int|string, mixed>  $original
     * @param  array<string, mixed>  $spec
     * @return array<int|string, mixed>
     */
    private function blocks(array $merged, array $original, array $spec): array
    {
        $byId = [];

        foreach ($original as $block) {
            if (is_array($block) && isset($block['id'])) {
                $byId[$block['id']] = $block;
            }
        }

        foreach ($merged as $i => $block) {
            $was = is_array($block) ? ($byId[$block['id'] ?? null] ?? null) : null;

            if ($was !== null && ($block['type'] ?? null) === ($was['type'] ?? null)) {
                $merged[$i] = $this->keep($block, $was, $spec['sets'][$block['type']]['fields'] ?? []);
            }
        }

        return $merged;
    }

    /**
     * Rows are matched to the entry's by position, as the merger matches them.
     *
     * @param  array<int|string, mixed>  $merged
     * @param  array<int|string, mixed>  $original
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int|string, mixed>
     */
    private function rows(array $merged, array $original, array $fields): array
    {
        foreach ($merged as $i => $row) {
            if (is_array($row) && is_array($original[$i] ?? null)) {
                $merged[$i] = $this->keep($row, $original[$i], $fields);
            }
        }

        return $merged;
    }

    /**
     * @param  array<int|string, mixed>  $built
     * @param  array<int|string, mixed>  $original
     * @return array<int, mixed>
     */
    private function nodes(array $built, array $original): array
    {
        $built = array_values($built);
        $present = [];

        foreach ($built as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'set' && isset($node['attrs']['id'])) {
                $present[$node['attrs']['id']] = true;
            }
        }

        // Each set the draft didn't hold, by how many of the other nodes came before it.
        $after = [];
        $seen = 0;

        foreach ($original as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'set' && ! $this->toMarkdown->carries((array) ($node['attrs']['values'] ?? []))) {
                if (! isset($present[$node['attrs']['id'] ?? null])) {
                    $after[$seen][] = $node;
                }

                continue;
            }

            $seen++;
        }

        if ($after === []) {
            return $built;
        }

        $nodes = [...($after[0] ?? [])];
        unset($after[0]);

        foreach ($built as $i => $node) {
            $nodes[] = $node;
            $nodes = [...$nodes, ...($after[$i + 1] ?? [])];
            unset($after[$i + 1]);
        }

        // The draft is shorter now: what followed the missing nodes goes at the end.
        foreach ($after as $sets) {
            $nodes = [...$nodes, ...$sets];
        }

        return $nodes;
    }
}
