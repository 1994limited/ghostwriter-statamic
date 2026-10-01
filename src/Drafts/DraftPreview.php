<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Lays a draft out for reading: each field with its label, markdown rendered,
 * blocks shown in order under their names. The screen draws this tree rather
 * than the raw YAML.
 */
class DraftPreview
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<int, array<string, mixed>>
     */
    public function render(array $data, array $schema): array
    {
        $nodes = [];

        foreach ($schema as $spec) {
            if (! array_key_exists($spec['handle'], $data)) {
                continue;
            }

            $value = $data[$spec['handle']];

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $nodes[] = ['handle' => $spec['handle'], 'label' => $spec['display'] ?: $spec['handle']] + $this->node($value, $spec);
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function node(mixed $value, array $spec): array
    {
        return match ($spec['kind']) {
            'richtext' => ['kind' => 'html', 'html' => $this->html((string) $value)],
            'blocks' => ['kind' => 'blocks', 'items' => array_values(array_map(function ($block) use ($spec) {
                $type = is_array($block) ? (string) ($block['type'] ?? '') : '';
                $set = $spec['sets'][$type] ?? null;

                return [
                    'type' => $type,
                    'label' => $set['display'] ?? $type,
                    'known' => $set !== null,
                    'fields' => $set ? $this->render((array) $block, $set['fields']) : [],
                ];
            }, (array) $value))],
            'rows' => ['kind' => 'rows', 'items' => array_values(array_map(
                fn ($row) => $this->render((array) $row, $spec['fields'] ?? []),
                (array) $value,
            ))],
            'group' => ['kind' => 'group', 'fields' => $this->render((array) $value, $spec['fields'] ?? [])],
            'list', 'choices' => ['kind' => 'list', 'items' => array_values(array_map('strval', array_filter((array) $value, 'is_scalar')))],
            'toggle' => ['kind' => 'text', 'text' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No'],
            default => ['kind' => 'text', 'text' => is_scalar($value) ? (string) $value : json_encode($value)],
        };
    }

    /**
     * Raw HTML in the markdown is escaped: the text came from a model and is
     * shown with v-html.
     */
    private function html(string $markdown): string
    {
        $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }
}
