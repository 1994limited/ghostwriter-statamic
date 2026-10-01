<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use League\CommonMark\CommonMarkConverter;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;

/**
 * Turns a draft into the data an entry stores, guided by the blueprint's
 * schema: markdown becomes a Bard document (or stays markdown, or becomes
 * HTML, as the field requires), blocks and rows get their IDs, choices are
 * checked against their options, and any value the draft left out that is a
 * house default on this collection is filled in.
 *
 * Anything in the draft that the blueprint has no place for is dropped and
 * reported, never saved.
 */
class EntryBuilder
{
    /** @var array<int, string> */
    private array $notes = [];

    /** @var array<int, string> */
    private array $toFill = [];

    public function __construct(private MarkdownToBard $bard) {}

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $pattern
     * @param  array<string, mixed>  $defaults
     * @return array{data: array<string, mixed>, notes: array<int, string>}
     */
    public function build(array $draft, array $schema, array $pattern = [], array $defaults = []): array
    {
        $this->notes = [];
        $this->toFill = [];

        $data = $this->fields($draft, $schema, $pattern['blocks'] ?? []);

        if ($this->toFill) {
            $this->notes[] = 'Still to choose by hand: '.implode('; ', array_unique($this->toFill)).'.';
        }

        // The type's own defaults win over what the collection usually does.
        foreach ($defaults + ($pattern['fixed'] ?? []) as $key => $value) {
            $data[$key] ??= $value;
        }

        return ['data' => $data, 'notes' => $this->notes];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $blockPatterns
     * @return array<string, mixed>
     */
    private function fields(array $values, array $schema, array $blockPatterns = []): array
    {
        $data = [];
        $known = array_column($schema, 'handle');

        foreach (array_diff(array_keys($values), $known, ['type']) as $stray) {
            $this->notes[] = "\"{$stray}\" is not a field here and was left out.";
        }

        foreach ($schema as $spec) {
            if (! SchemaReader::writable($spec) || ! array_key_exists($spec['handle'], $values)) {
                continue;
            }

            $value = $this->value($values[$spec['handle']], $spec, $blockPatterns[$spec['handle']] ?? []);

            if ($value !== null) {
                $data[$spec['handle']] = $value;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $pattern
     */
    private function value(mixed $value, array $spec, array $pattern): mixed
    {
        return match ($spec['kind']) {
            'text' => is_scalar($value) ? trim(preg_replace('/\s+/', ' ', (string) $value) ?? '') : null,
            'longtext' => is_scalar($value) ? trim((string) $value) : null,
            'richtext' => is_scalar($value) ? $this->richtext(trim((string) $value), $spec) : null,
            'choice' => $this->choice($value, $spec),
            'choices' => array_values(array_filter(array_map(fn ($v) => $this->choice($v, $spec), (array) $value), fn ($v) => $v !== null)),
            'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($value) ? $value + 0 : null,
            'list' => array_values(array_map('strval', array_filter((array) $value, 'is_scalar'))),
            'blocks' => $this->blocks((array) $value, $spec, $pattern),
            'rows' => array_values(array_map(
                fn (array $row) => ['id' => $this->id()] + $this->fields($row, $spec['fields'] ?? []),
                array_filter((array) $value, 'is_array'),
            )),
            'group' => is_array($value) ? $this->fields($value, $spec['fields'] ?? []) : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function richtext(string $markdown, array $spec): mixed
    {
        if ($spec['type'] !== 'bard') {
            return $markdown;
        }

        if ($spec['save_html'] ?? false) {
            return trim((string) (new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($markdown));
        }

        return $this->withQuoteSets($this->bard->convert($markdown), $spec['sets'] ?? []);
    }

    /**
     * Where a Bard field offers a pull-quote style set, block quotes are
     * stored as that set, which is what editors on the site would have used.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $sets
     * @return array<int, array<string, mixed>>
     */
    private function withQuoteSets(array $nodes, array $sets): array
    {
        $quoteSet = null;
        $quoteField = null;

        foreach ($sets as $handle => $set) {
            if (! str_contains((string) $handle, 'quote')) {
                continue;
            }

            foreach ($set['fields'] as $field) {
                if (in_array($field['kind'], ['text', 'longtext'], true)) {
                    [$quoteSet, $quoteField] = [$handle, $field['handle']];
                    break 2;
                }
            }
        }

        if ($quoteSet === null) {
            return $nodes;
        }

        return array_map(function (array $node) use ($quoteSet, $quoteField): array {
            if ($node['type'] !== 'blockquote') {
                return $node;
            }

            return ['type' => 'set', 'attrs' => ['id' => $this->id(), 'values' => [
                'type' => $quoteSet,
                $quoteField => trim($this->plain($node)),
            ]]];
        }, $nodes);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function plain(array $node): string
    {
        if (isset($node['text'])) {
            $italic = collect($node['marks'] ?? [])->contains('type', 'italic');

            return $italic ? '*'.$node['text'].'*' : $node['text'];
        }

        return implode(($node['type'] ?? '') === 'blockquote' ? "\n\n" : '', array_map(fn ($child) => $this->plain((array) $child), $node['content'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function choice(mixed $value, array $spec): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $options = $spec['options'] ?? [];

        if ($options === [] || array_key_exists($value, $options)) {
            return $value;
        }

        // The model may have written the label rather than the key.
        $key = array_search(strtolower($value), array_map('strtolower', $options), true);

        if ($key !== false) {
            return (string) $key;
        }

        $this->notes[] = "\"{$value}\" is not an option for {$spec['handle']} and was left out.";

        return null;
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $pattern
     * @return array<int, array<string, mixed>>
     */
    private function blocks(array $blocks, array $spec, array $pattern): array
    {
        $out = [];

        foreach ($blocks as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;

            if (! is_string($type) || ! isset($spec['sets'][$type])) {
                $this->notes[] = 'A block of type "'.(is_scalar($type) ? $type : '?').'" does not exist in '.$spec['handle'].' and was left out.';

                continue;
            }

            $fields = $this->fields($block, $spec['sets'][$type]['fields']);

            // House defaults for this block: its usual settings, and for a
            // boilerplate block its usual content too.
            // A boilerplate block is always the copy, whatever the writer
            // put in it: its wording is not the writer's to change.
            $copied = in_array($type, $pattern['boilerplate'] ?? [], true);

            $fixed = $pattern['fixed'][$type] ?? [];

            $changed = array_filter(array_intersect_key($block, $fixed), fn ($value, $key) => ! is_string($value) || trim($value) !== $fixed[$key], ARRAY_FILTER_USE_BOTH);

            if ($copied && $changed !== []) {
                $this->notes[] = $spec['sets'][$type]['display'].' is the same on every entry here, so its usual content was used in place of what was drafted.';
            }

            foreach ($fixed as $key => $value) {
                if ($copied || ! isset($fields[$key])) {
                    $fields[$key] = $this->withFreshIds($value);
                }
            }

            // Images, links and chosen entries are a person's to pick. Name
            // the ones this kind of entry normally has, so none is missed.
            foreach ($spec['sets'][$type]['fields'] as $field) {
                $expected = in_array($field['handle'], $pattern['used'][$type] ?? [], true);

                if ($expected && ! SchemaReader::writable($field) && ! isset($fields[$field['handle']])) {
                    $this->toFill[] = $spec['sets'][$type]['display'].': '.($field['display'] ?: $field['handle']);
                }
            }

            $out[] = ['id' => $this->id(), 'type' => $type, 'enabled' => true] + $fields;
        }

        return $out;
    }

    /**
     * Copied content keeps its shape but not its IDs, which must be unique
     * to the rows and sets of the entry they end up in.
     */
    private function withFreshIds(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $key === 'id' && is_string($item) ? $this->id() : $this->withFreshIds($item);
        }

        return $value;
    }

    private function id(): string
    {
        return bin2hex(random_bytes(4));
    }
}
