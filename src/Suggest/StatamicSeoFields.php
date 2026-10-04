<?php

namespace NineteenNinetyFour\Ghostwriter\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\TitleFormat;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use Statamic\Facades\Markdown;
use Statamic\Modifiers\CoreModifiers;
use Throwable;

/**
 * Where a Statamic entry's SEO title and description are, and what the
 * page prints for them:
 *
 * - **SEO Pro:** a `seo_pro` field (usually `seo`) whose `title` and
 *   `description` resolve as SEO Pro's cascade does (`Cascade::parse()`):
 *   the entry's value, else its collection's `inject: seo:` default (the
 *   EntryData's group), else the site defaults (`resources/addons/
 *   seo-pro.yaml`, the EntryData's site). A string is custom text at the
 *   entry and a default above it; `@seo:<field>` (or `@seo:<collection>/
 *   <field>`) is that field of the entry, a Bard field read as its plain
 *   text, and an empty one falls back to the section's default, then the
 *   site's; a string with Antlers is a template core doesn't evaluate;
 *   `false` is switched off, and the whole field `false` prints nothing.
 *   Robots (`robots_indexing`, or the legacy `robots: [noindex]`) and the
 *   site name, separator and position come through the same cascade.
 * - **Plain fields:** `seo_title`, `meta_title`, `seo_description`,
 *   `meta_description` (core's PlainSeoFields), with the field's
 *   `character_limit` as the limit, and a `noindex` toggle.
 */
class StatamicSeoFields implements SeoFields
{
    private const ROLES = [SeoField::TITLE => 'title', SeoField::DESCRIPTION => 'description'];

    private const LABELS = [SeoField::TITLE => 'SEO title', SeoField::DESCRIPTION => 'SEO description'];

    /** SEO Pro cuts a description longer than this, in bytes (parseDescriptionField()). */
    private const DESCRIPTION_CUT = 320;

    public function __construct(private SeoProDefaults $defaults) {}

    public function in(Schema $schema, EntryData $entry): array
    {
        $found = [];

        foreach ($schema->fields as $field) {
            if ($field->type === 'seo_pro') {
                array_push($found, ...$this->seoPro($field, $schema, $entry));
            }
        }

        return [...$found, ...(new PlainSeoFields)->in($schema, $entry)];
    }

    public function noindex(Schema $schema, EntryData $entry): ?bool
    {
        $field = $this->seoProField($schema);

        if ($field === null) {
            return (new PlainSeoFields)->noindex($schema, $entry);
        }

        $own = self::own($entry->get($field->handle));

        // Switched off: SEO Pro prints no robots tag at all.
        if ($own === false) {
            return false;
        }

        $site = $this->defaults->site($entry->site);
        $section = $this->defaults->section($entry->group);
        $data = [...$site, ...$section, ...$own];

        // As Cascade::robots(): the legacy list when only the entry has it and not the new keys.
        $legacyFromEntry = array_key_exists('robots', $own) && ! isset($site['robots']) && ! isset($section['robots']);
        $newFromEntry = array_filter(['robots_indexing', 'robots_following'], fn (string $key) => array_key_exists($key, $own) && ! isset($site[$key]) && ! isset($section[$key])) !== [];

        if (! ($legacyFromEntry && ! $newFromEntry) && is_string($data['robots_indexing'] ?? null) && $data['robots_indexing'] !== '') {
            return $data['robots_indexing'] === 'noindex';
        }

        return in_array('noindex', self::legacyRobots($data['robots'] ?? null), true);
    }

    public function titleFormat(Schema $schema, EntryData $entry): ?TitleFormat
    {
        $field = $this->seoProField($schema);
        $own = $field === null ? false : self::own($entry->get($field->handle));

        if ($own === false) {
            return null;
        }

        $data = [...$this->defaults->site($entry->site), ...$this->defaults->section($entry->group), ...$own];
        $name = is_string($data['site_name'] ?? null) ? $this->defaults->evaluate($data['site_name']) : '';

        return TitleFormat::of(
            $name ?? '',
            is_scalar($data['site_name_separator'] ?? null) ? (string) $data['site_name_separator'] : '',
            is_string($data['site_name_position'] ?? null) ? $data['site_name_position'] : null,
        );
    }

    /**
     * @return list<SeoField>
     */
    private function seoPro(Field $field, Schema $schema, EntryData $entry): array
    {
        $own = self::own($entry->get($field->handle));
        $section = $this->defaults->section($entry->group);
        $site = $this->defaults->site($entry->site);
        $found = [];

        foreach (self::ROLES as $role => $key) {
            $path = FieldPath::of($field->handle)->with($key);
            $label = __(self::LABELS[$role]);

            // The whole field switched off for this entry: SEO Pro prints nothing.
            if ($own === false) {
                $found[] = new SeoField($path, $role, $label, SeoField::LIMITS[$role], null, false, source: SeoSource::Disabled);

                continue;
            }

            [$raw, $atEntry] = match (true) {
                array_key_exists($key, $own) => [$own[$key], true],
                array_key_exists($key, $section) => [$section[$key], false],
                default => [$site[$key] ?? null, false],
            };

            $found[] = $this->resolve($raw, $atEntry, $key, $section, $site, false, $path, $role, $label, $schema, $entry);
        }

        return $found;
    }

    /**
     * One value as SEO Pro's Cascade::parse() resolves it.
     *
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $site
     */
    private function resolve(mixed $raw, bool $atEntry, string $key, array $section, array $site, bool $triedSection, FieldPath $path, string $role, string $label, Schema $schema, EntryData $entry): SeoField
    {
        $limit = SeoField::LIMITS[$role];

        if ($raw === false) {
            return new SeoField($path, $role, $label, $limit, null, false, source: SeoSource::Disabled);
        }

        if (! is_string($raw) || trim($raw) === '') {
            // Set nowhere: the page prints none, and the entry can have its own.
            return new SeoField($path, $role, $label, $limit, '', source: SeoSource::Custom);
        }

        if (str_contains($raw, '{{')) {
            return new SeoField($path, $role, $label, $limit, null, false, source: SeoSource::Template);
        }

        if (! str_starts_with($raw, '@seo:')) {
            return new SeoField($path, $role, $label, $limit, $this->printed($key, $raw), source: $atEntry ? SeoSource::Custom : SeoSource::Default);
        }

        // `@seo:excerpt`, or `@seo:journal/excerpt`, which SEO Pro reads from the entry all the same.
        $source = substr($raw, 5);
        $source = str_contains($source, '/') ? explode('/', $source)[1] : $source;
        $text = $this->printed($key, $this->textOf($schema->field($source), $entry->get($source)));

        if ($text === '') {
            if (! $triedSection && isset($section[$key]) && $section[$key] !== $raw) {
                return $this->resolve($section[$key], false, $key, $section, $site, true, $path, $role, $label, $schema, $entry);
            }

            if (isset($site[$key]) && $site[$key] !== $raw) {
                return $this->resolve($site[$key], false, $key, $section, $site, $triedSection, $path, $role, $label, $schema, $entry);
            }
        }

        return new SeoField($path, $role, $label, $limit, $text, true, $this->labelOf($schema, $source), SeoSource::Field);
    }

    /** A field's value as plain text, as SEO Pro reads it: a Bard field's text, Markdown's rendered text, anything else as a string. */
    private function textOf(?Field $field, mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') {
            return '';
        }

        try {
            if ($field?->type === 'bard' || is_array($value)) {
                return (string) (new CoreModifiers)->bardText($value);
            }

            if ($field?->type === 'markdown' && is_string($value)) {
                return strip_tags((string) Markdown::parse($value));
            }
        } catch (Throwable) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** The text as SEO Pro prints it: a title trimmed; a description without tags, cut at 320 bytes. */
    private function printed(string $key, string $text): string
    {
        $text = trim($key === 'description' ? strip_tags($text) : $text);

        if ($key === 'description' && strlen($text) > self::DESCRIPTION_CUT) {
            $text = mb_strcut($text, 0, self::DESCRIPTION_CUT).'...';
        }

        return $text;
    }

    private function seoProField(Schema $schema): ?Field
    {
        foreach ($schema->fields as $field) {
            if ($field->type === 'seo_pro') {
                return $field;
            }
        }

        return null;
    }

    /**
     * The entry's own SEO Pro values, nulls left out (they inherit), as
     * stored (`'@seo:excerpt'`, a string, `false`) or as the publish form
     * holds them (`{source, value}`, `enabled`); false when the whole
     * field is switched off.
     *
     * @return array<string, mixed>|false
     */
    private static function own(mixed $value): array|false
    {
        if ($value === false || (is_array($value) && array_key_exists('enabled', $value) && ! $value['enabled'])) {
            return false;
        }

        $own = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_array($item) && isset($item['source'])) {
                $item = match ($item['source']) {
                    'field' => is_string($item['value'] ?? null) && $item['value'] !== '' ? '@seo:'.$item['value'] : null,
                    'disable' => false,
                    'custom' => $item['value'] ?? null,
                    default => null,
                };
            }

            if ($item !== null && $item !== '' && $key !== 'enabled') {
                $own[(string) $key] = $item;
            }
        }

        return $own;
    }

    /**
     * The legacy `robots` list: `[noindex, nofollow]`, or the options' `{key}` shape.
     *
     * @return list<string>
     */
    private static function legacyRobots(mixed $robots): array
    {
        if (is_string($robots)) {
            $robots = [$robots];
        }

        return array_values(array_filter(array_map(fn ($item) => is_array($item) ? ($item['key'] ?? $item['value'] ?? null) : $item, is_array($robots) ? $robots : []), 'is_string'));
    }

    private function labelOf(Schema $schema, string $handle): string
    {
        $field = $schema->field($handle);

        return $field !== null && $field->label !== '' ? $field->label : ucfirst(str_replace('_', ' ', $handle));
    }
}
