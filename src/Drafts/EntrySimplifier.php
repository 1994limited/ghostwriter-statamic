<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;

/**
 * Reduces a stored entry to the form drafts are written in: only the fields
 * the writer fills, rich text as markdown, and none of the IDs or references
 * the Control Panel adds. It is how existing entries are shown to the model
 * as examples, in exactly the shape it is asked to produce.
 */
class EntrySimplifier
{
    public function __construct(private BardToMarkdown $markdown) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    public function simplify(array $data, array $schema): array
    {
        $out = [];

        foreach ($schema as $spec) {
            if (! SchemaReader::writable($spec) || ! array_key_exists($spec['handle'], $data)) {
                continue;
            }

            $value = $this->value($data[$spec['handle']], $spec);

            if ($value !== null && $value !== '' && $value !== []) {
                $out[$spec['handle']] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function value(mixed $value, array $spec): mixed
    {
        return match ($spec['kind']) {
            'richtext' => is_array($value) ? $this->markdown->convert($value) : trim((string) $value),
            'blocks' => $this->blocks((array) $value, $spec),
            'rows' => array_values(array_filter(array_map(
                fn ($row) => is_array($row) ? $this->simplify($row, $spec['fields'] ?? []) : null,
                (array) $value,
            ))),
            'group' => is_array($value) ? $this->simplify($value, $spec['fields'] ?? []) : null,
            'text', 'longtext' => is_string($value) ? trim($value) : null,
            default => is_scalar($value) || is_array($value) ? $value : null,
        };
    }

    /**
     * @param  array<int, mixed>  $sets
     * @param  array<string, mixed>  $spec
     * @return array<int, array<string, mixed>>
     */
    private function blocks(array $sets, array $spec): array
    {
        $out = [];

        foreach ($sets as $set) {
            // A set switched off in the Control Panel is not part of the page.
            if (! is_array($set) || ($set['enabled'] ?? true) === false || ! isset($set['type'])) {
                continue;
            }

            $fields = $spec['sets'][$set['type']]['fields'] ?? null;

            if ($fields === null) {
                continue;
            }

            $out[] = ['type' => $set['type']] + $this->simplify($set, $fields);
        }

        return $out;
    }
}
