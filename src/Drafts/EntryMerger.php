<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

/**
 * Lays a rewritten draft over the entry it was made from. The writer only
 * deals in words, so everything else an existing entry holds (images, links,
 * chosen entries, settings, the IDs of its blocks and rows) is carried over
 * from the entry and only the writing changes.
 */
class EntryMerger
{
    /**
     * @param  array<string, mixed>  $built  Entry data built from the revised draft.
     * @param  array<string, mixed>  $original  The entry's data as saved.
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    public function merge(array $built, array $original, array $schema): array
    {
        $specs = array_column($schema, null, 'handle');

        foreach ($built as $handle => $value) {
            $spec = $specs[$handle] ?? null;
            $was = $original[$handle] ?? null;

            if (! $spec || ! is_array($value) || ! is_array($was)) {
                continue;
            }

            $built[$handle] = match ($spec['kind']) {
                'blocks' => $this->blocks($value, $was, $spec),
                'rows' => $this->rows($value, $was, $spec['fields'] ?? []),
                'group' => $this->merge($value, $was, $spec['fields'] ?? []) + $was,
                default => $value,
            };
        }

        return $built;
    }

    /**
     * Blocks are matched by type, in order: the second text block of the
     * draft is the entry's second text block, wherever either now sits.
     *
     * @param  array<int, array<string, mixed>>  $built
     * @param  array<int, mixed>  $original
     * @param  array<string, mixed>  $spec
     * @return array<int, array<string, mixed>>
     */
    private function blocks(array $built, array $original, array $spec): array
    {
        $waiting = [];
        $hidden = [];

        foreach ($original as $block) {
            if (! is_array($block) || ! isset($block['type'])) {
                continue;
            }

            // The draft never saw blocks that are switched off; they are
            // kept as they were, after the rest.
            if (($block['enabled'] ?? true) === false) {
                $hidden[] = $block;
            } else {
                $waiting[$block['type']][] = $block;
            }
        }

        $merged = [];

        foreach ($built as $block) {
            $was = array_shift($waiting[$block['type']]) ?? null;

            if ($was === null) {
                $merged[] = $block;

                continue;
            }

            $fields = $spec['sets'][$block['type']]['fields'] ?? [];

            $merged[] = ['id' => $was['id'] ?? $block['id']] + $this->merge($block, $was, $fields) + $was;
        }

        return [...$merged, ...$hidden];
    }

    /**
     * @param  array<int, mixed>  $built
     * @param  array<int, mixed>  $original
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, mixed>
     */
    private function rows(array $built, array $original, array $fields): array
    {
        foreach ($built as $i => $row) {
            $was = $original[$i] ?? null;

            if (is_array($row) && is_array($was)) {
                $built[$i] = ['id' => $was['id'] ?? ($row['id'] ?? null)] + $this->merge($row, $was, $fields) + $was;
            }
        }

        return $built;
    }
}
