<?php

namespace NineteenNinetyFour\Ghostwriter\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * Where a Statamic entry's SEO title and description are:
 *
 * - **SEO Pro:** a `seo_pro` field (usually `seo`) whose `title` and
 *   `description` are each a custom string, `@seo:<field>` (that field's
 *   text, which Ghostwriter checks and can replace with a value of the
 *   page's own), missing or null (the section's cascade, an Antlers
 *   template it doesn't evaluate: not checked), or `false` (switched off).
 * - **Plain fields:** `seo_title`, `meta_title`, `seo_description`,
 *   `meta_description` (core's PlainSeoFields), with the field's
 *   `character_limit` as the limit.
 */
class StatamicSeoFields implements SeoFields
{
    private const ROLES = [SeoField::TITLE => 'title', SeoField::DESCRIPTION => 'description'];

    private const LABELS = [SeoField::TITLE => 'SEO title', SeoField::DESCRIPTION => 'SEO description'];

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

    /**
     * @return list<SeoField>
     */
    private function seoPro(Field $field, Schema $schema, EntryData $entry): array
    {
        $value = $entry->get($field->handle);

        // The whole field switched off for this entry.
        if ($value === false) {
            return [];
        }

        $values = is_array($value) ? $value : [];
        $found = [];

        foreach (self::ROLES as $role => $key) {
            $raw = $values[$key] ?? null;
            $path = FieldPath::of($field->handle)->with($key);
            $label = __(self::LABELS[$role]);

            if ($raw === false) {
                continue;
            }

            if (is_string($raw) && str_starts_with($raw, '@seo:')) {
                $source = substr($raw, 5);
                $text = $entry->get($source);
                $found[] = new SeoField($path, $role, $label, SeoField::LIMITS[$role], is_scalar($text) ? (string) $text : '', true, $this->labelOf($schema, $source));

                continue;
            }

            if (is_string($raw) && trim($raw) !== '') {
                $found[] = new SeoField($path, $role, $label, SeoField::LIMITS[$role], $raw);

                continue;
            }

            // Inherited from the section's defaults: an Antlers template.
            $found[] = new SeoField($path, $role, $label, SeoField::LIMITS[$role], null, false);
        }

        return $found;
    }

    private function labelOf(Schema $schema, string $handle): string
    {
        foreach ($schema->fields as $field) {
            if ($field->handle === $handle) {
                return $field->label !== '' ? $field->label : ucfirst(str_replace('_', ' ', $handle));
            }
        }

        return ucfirst(str_replace('_', ' ', $handle));
    }
}
