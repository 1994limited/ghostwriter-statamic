<?php

namespace NineteenNinetyFour\Ghostwriter\Storage;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfiles;

/**
 * How each collection's template prints headings (core's Seo\RenderProfile),
 * read from the Preview tab's renders: one JSON file beside the sessions,
 * `render-profiles.json`, keyed by collection, blueprint and site.
 */
class FileRenderProfiles implements RenderProfiles
{
    use JsonFiles;

    public function __construct(private Lock $lock) {}

    /** The key for a collection's blueprint on a site: `pages.page.default`. */
    public static function key(string $collection, string $blueprint, string $site): string
    {
        return "{$collection}.{$blueprint}.{$site}";
    }

    public function get(string $key): ?RenderProfile
    {
        $profile = $this->read()[$key] ?? null;

        return is_array($profile) ? RenderProfile::fromArray($profile) : null;
    }

    /**
     * The profile for a collection's blueprint: the site's own, or else
     * another site's (templates are usually shared), or null.
     */
    public function for(string $collection, string $blueprint, ?string $site = null): ?RenderProfile
    {
        if ($site !== null && ($profile = $this->get(self::key($collection, $blueprint, $site))) !== null) {
            return $profile;
        }

        foreach ($this->read() as $key => $profile) {
            if (is_array($profile) && str_starts_with((string) $key, "{$collection}.{$blueprint}.")) {
                return RenderProfile::fromArray($profile);
            }
        }

        return null;
    }

    public function put(RenderProfile $profile): void
    {
        $this->lock->run('render-profiles', function () use ($profile) {
            $all = $this->read();
            $all[$profile->key] = $profile->toArray();
            ksort($all);
            $this->writeJson($this->path(), $all);
        });
    }

    public function all(): array
    {
        return array_values(array_map(fn (array $profile) => RenderProfile::fromArray($profile), array_filter($this->read(), 'is_array')));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $data = $this->readJson($this->path()) ?? [];
        $out = [];

        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private function path(): string
    {
        return $this->stateDirectory().'/render-profiles.json';
    }
}
