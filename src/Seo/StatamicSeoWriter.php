<?php

namespace NineteenNinetyFour\Ghostwriter\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Seo\PlainSeoWriter;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoWriter;

/**
 * Writes a search title or description into a Statamic entry's values
 * (core's SeoWriter, SEO layer §9.3), for StatamicSeoFields:
 *
 * - **SEO Pro** (`seo.title`, `seo.description`): the text as the key's
 *   own, custom value. Stored, that is the string; in the publish form,
 *   SEO Pro holds each key as `{source, value}`, so there it is
 *   `{source: 'custom', value: text}`. The shape follows what the values
 *   already hold at that key (or `form: true`), and every other key of the
 *   `seo` value is kept. Suggest edits and Finish this page write the same
 *   shape in the browser (`resources/js/seo-value.js`).
 * - **Plain fields** (`seo_title`, `meta_description`…): the field's text
 *   (core's PlainSeoWriter).
 *
 * It only writes: whether a value may be written is MetaPolicy's call,
 * made first (SearchFields::apply()). Nothing is saved.
 */
final class StatamicSeoWriter implements SeoWriter
{
    /**
     * @param  bool  $form  Write SEO Pro's publish-form shape whatever the values hold.
     */
    public function __construct(private readonly bool $form = false) {}

    public function write(array $values, SeoField $field, string $text): array
    {
        $segments = $field->path->segments;

        // A plain field, or anything deeper than SEO Pro's `seo.<key>`.
        if (count($segments) !== 2 || ! is_string($segments[0]) || ! is_string($segments[1])) {
            return (new PlainSeoWriter)->write($values, $field, $text);
        }

        [$handle, $key] = $segments;
        $seo = is_array($values[$handle] ?? null) ? $values[$handle] : [];

        $seo[$key] = self::value($seo[$key] ?? null, $text, $this->form || self::isForm($seo));
        $values[$handle] = $seo;

        return $values;
    }

    /**
     * One SEO Pro key's custom value: the string as stored, or
     * `{source: 'custom', value}` where the form's shape is wanted or the
     * key already has it.
     *
     * @return string|array{source: string, value: string}
     */
    public static function value(mixed $current, string $text, bool $form = false): string|array
    {
        return $form || (is_array($current) && array_key_exists('source', $current))
            ? ['source' => 'custom', 'value' => $text]
            : $text;
    }

    /**
     * Whether SEO Pro's value is in the publish form's shape: `enabled`, or
     * any key as `{source, value}`.
     *
     * @param  array<string, mixed>  $seo
     */
    private static function isForm(array $seo): bool
    {
        if (array_key_exists('enabled', $seo)) {
            return true;
        }

        foreach ($seo as $item) {
            if (is_array($item) && array_key_exists('source', $item)) {
                return true;
            }
        }

        return false;
    }
}
