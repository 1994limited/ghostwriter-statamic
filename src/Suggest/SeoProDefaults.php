<?php

namespace NineteenNinetyFour\Ghostwriter\Suggest;

use Illuminate\Support\Facades\File;
use Statamic\Facades\Antlers;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * SEO Pro's defaults, where its cascade reads them: a collection's
 * `inject: seo:` (`$collection->cascade('seo')`, the section defaults),
 * and the site defaults in `resources/addons/seo-pro.yaml` (`site_defaults`,
 * keyed by site handle on a multi-site install), through SEO Pro's own
 * `SiteDefaults::in()` where it's installed. Without a file, SEO Pro's
 * out-of-the-box defaults.
 *
 * Read only; nothing here saves.
 */
class SeoProDefaults
{
    /** SEO Pro's own defaults when no site defaults are saved (SiteDefaults::defaultValues()). */
    public const BUILT_IN = [
        'site_name' => '{{ config:app:name }}',
        'site_name_position' => 'after',
        'site_name_separator' => '|',
        'title' => '@seo:title',
        'description' => '@seo:content',
    ];

    private const SITE_DEFAULTS = 'Statamic\SeoPro\SiteDefaults\SiteDefaults';

    /**
     * A collection's section defaults.
     *
     * @return array<string, mixed>
     */
    public function section(?string $collection): array
    {
        if ($collection === null || $collection === '') {
            return [];
        }

        try {
            $cascade = Collection::findByHandle($collection)?->cascade('seo');
        } catch (Throwable) {
            return [];
        }

        return is_array($cascade) ? $cascade : [];
    }

    /**
     * A site's defaults.
     *
     * @return array<string, mixed>
     */
    public function site(?string $site): array
    {
        $site = $site !== null && $site !== '' ? $site : Site::default()->handle();

        if (class_exists(self::SITE_DEFAULTS)) {
            try {
                $localized = (self::SITE_DEFAULTS)::in($site);

                return $localized === null ? [] : $localized->values()->all();
            } catch (Throwable) {
                // Read the file as below.
            }
        }

        return $this->fromFile($site);
    }

    /**
     * A setting that may be Antlers with no page in it (the site name,
     * `{{ config:app:name }}` by default), as SEO Pro prints it; null when
     * it can't be evaluated here.
     */
    public function evaluate(string $value): ?string
    {
        if (! str_contains($value, '{{')) {
            return $value;
        }

        try {
            return trim((string) Antlers::parse($value, []));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fromFile(string $site): array
    {
        $path = resource_path('addons/seo-pro.yaml');

        if (! File::exists($path)) {
            return self::BUILT_IN;
        }

        try {
            $settings = Yaml::parse((string) File::get($path));
        } catch (Throwable) {
            return self::BUILT_IN;
        }

        $defaults = is_array($settings) && is_array($settings['site_defaults'] ?? null) ? $settings['site_defaults'] : [];

        if (Site::multiEnabled()) {
            $defaults = is_array($defaults[$site] ?? null) ? $defaults[$site] : [];
        }

        return $defaults === [] ? self::BUILT_IN : $defaults;
    }
}
