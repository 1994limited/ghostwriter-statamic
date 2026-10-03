<?php

namespace NineteenNinetyFour\Ghostwriter\Blueprints;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Reads a blueprint into the plain description of its fields that the rest
 * of the addon works from. Every site's blueprints are different: a single
 * Bard body on one, a page builder of replicator sets on the next. Reducing
 * each field to a "kind" is what lets one writer serve them all.
 *
 * Kinds:
 *   text, longtext   plain strings
 *   richtext         written as markdown, stored as Bard or Markdown
 *   choice, choices  one or several of a fixed set of options
 *   toggle, number   a boolean, a number
 *   list             a list of short strings
 *   blocks           a replicator: a list of sets, each with its own fields
 *   rows             a grid: a list of rows sharing the same fields
 *   group            a nested set of fields
 *   reference        assets, entries, users, links, dates and the like, which
 *                    the writer leaves for a person to fill in
 */
class SchemaReader
{
    private const MAX_DEPTH = 5;

    private bool $listFolders = true;

    /** More files than this is a library, not a choice. */
    private const MAX_FILES = 80;

    private const KINDS = [
        'text' => 'text',
        'slug' => 'text',
        'color' => 'text',
        'textarea' => 'longtext',
        'markdown' => 'richtext',
        'bard' => 'richtext',
        'select' => 'choice',
        'radio' => 'choice',
        'button_group' => 'choice',
        'checkboxes' => 'choices',
        'toggle' => 'toggle',
        'integer' => 'number',
        'float' => 'number',
        'range' => 'number',
        'list' => 'list',
        'taggable' => 'list',
        'replicator' => 'blocks',
        'grid' => 'rows',
        'group' => 'group',
    ];

    /** Fieldtypes that hold no content at all. */
    private const IGNORED = ['section', 'html', 'spacer', 'hidden', 'revealer'];

    /**
     * The blueprint's fields as plain arrays ("specs"), as the addon's own
     * code reads them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function read(Blueprint $blueprint): array
    {
        return $this->fields($blueprint->fields(), 0);
    }

    /**
     * The blueprint's fields as core's schema, for the layout algorithms.
     */
    public function schema(Blueprint $blueprint): Schema
    {
        return Schema::fromSpecs($this->read($blueprint));
    }

    /**
     * The schema without listing any folder's files: for checks that run
     * while entries are saved (Finish this page, the publish guard), which
     * need no options and mustn't fill the container's file list early.
     */
    public function schemaWithoutFolders(Blueprint $blueprint): Schema
    {
        $this->listFolders = false;

        try {
            return $this->schema($blueprint);
        } finally {
            $this->listFolders = true;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fields(Fields $fields, int $depth): array
    {
        $specs = [];

        foreach ($fields->all() as $field) {
            if ($spec = $this->field($field, $depth)) {
                $specs[] = $spec;
            }
        }

        return $specs;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function field(Field $field, int $depth): ?array
    {
        $type = $field->type();

        // The slug is derived from the title, not written.
        if (in_array($type, self::IGNORED, true) || ($depth === 0 && $field->handle() === 'slug')) {
            return null;
        }

        $kind = self::KINDS[$type] ?? 'reference';

        if ($kind === 'choice' && $field->get('multiple')) {
            $kind = 'choices';
        }

        // An assets field tied to one small folder, such as a set of logos,
        // is a choice among the files there, which the writer can make.
        $files = $type === 'assets' && $this->listFolders ? $this->folderFiles($field) : [];

        if ($files !== []) {
            $kind = (int) $field->get('max_files') === 1 ? 'choice' : 'choices';
        }

        $spec = [
            'handle' => $field->handle(),
            'type' => $type,
            'kind' => $kind,
            'display' => (string) $field->display(),
            'instructions' => trim((string) $field->instructions()),
            'required' => $field->isRequired(),
        ];

        if (in_array($kind, ['choice', 'choices'], true)) {
            $spec['options'] = $files ?: $this->options($field->get('options', []));
        }

        // Where a generated image for this field would be stored.
        if ($type === 'assets') {
            $spec['container'] = (string) $field->get('container', '');
            $spec['folder'] = trim((string) $field->get('folder', ''), '/');
            $spec['max_files'] = $field->get('max_files');
            $spec['images'] = $this->acceptsImages($field);
        }

        if ($depth >= self::MAX_DEPTH) {
            return $spec;
        }

        if ($kind === 'blocks' || $type === 'bard') {
            $spec['sets'] = $this->sets((array) $field->get('sets', []), $depth);
        }

        if ($type === 'bard') {
            $spec['save_html'] = (bool) $field->get('save_html', false);
        }

        if ($kind === 'rows' || $kind === 'group') {
            $spec['fields'] = $this->fields(new Fields((array) $field->get('fields', [])), $depth + 1);
        }

        return $spec;
    }

    /**
     * Whether pictures may go in an assets field. Statamic limits a field
     * only through its validation, so a rule such as `mimes:pdf` is what
     * says a field is for documents.
     */
    private function acceptsImages(Field $field): bool
    {
        foreach ((array) $field->get('validate', []) as $rule) {
            if (! is_string($rule) || ! preg_match('/^(mimes|extensions|mimetypes):(.*)$/i', $rule, $m)) {
                continue;
            }

            $allowed = array_map('strtolower', array_map('trim', explode(',', $m[2])));

            if (array_intersect($allowed, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml', 'image/*']) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The files in the folder an assets field is limited to, keyed by the
     * path the field stores. Empty when the field is not limited to a folder
     * or the folder holds too many to choose between by name.
     *
     * @return array<string, string>
     */
    private function folderFiles(Field $field): array
    {
        $folder = trim((string) $field->get('folder', ''), '/');
        $container = $folder !== '' ? AssetContainer::find((string) $field->get('container', '')) : null;

        if (! $container) {
            return [];
        }

        $files = $container->assets($folder)->mapWithKeys(fn ($asset) => [$asset->path() => $asset->basename()])->sort()->all();

        return count($files) <= self::MAX_FILES ? $files : [];
    }

    /**
     * Sets are stored either flat or inside display groups; both come out as
     * one flat map keyed by set handle.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, array{display: string, instructions: string, fields: array<int, array<string, mixed>>}>
     */
    private function sets(array $config, int $depth): array
    {
        $sets = [];

        foreach ($config as $handle => $set) {
            if (! is_array($set)) {
                continue;
            }

            if (isset($set['sets']) && is_array($set['sets'])) {
                $sets += $this->sets($set['sets'], $depth);

                continue;
            }

            $sets[$handle] = [
                'display' => (string) ($set['display'] ?? ucfirst(str_replace('_', ' ', (string) $handle))),
                'instructions' => trim((string) ($set['instructions'] ?? '')),
                'fields' => $this->fields(new Fields((array) ($set['fields'] ?? [])), $depth + 1),
            ];
        }

        return $sets;
    }

    /**
     * Options are written as `key: label`, as a plain list, or as a list of
     * `{key, value}` pairs depending on how the blueprint was saved.
     *
     * @return array<string, string>
     */
    private function options(mixed $options): array
    {
        $normalised = [];

        foreach ((array) $options as $key => $value) {
            if (is_array($value) && array_key_exists('key', $value)) {
                $normalised[(string) $value['key']] = (string) ($value['value'] ?? $value['key']);
            } elseif (is_int($key)) {
                $normalised[(string) $value] = (string) $value;
            } else {
                $normalised[(string) $key] = (string) ($value ?? $key);
            }
        }

        return $normalised;
    }
}
