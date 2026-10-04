<?php

namespace NineteenNinetyFour\Ghostwriter\Seo;

use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use NineteenNinetyFour\Ghostwriter\Drafts\BardDialect;
use NineteenNinetyFour\Ghostwriter\Storage\FileRenderProfiles;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;

/**
 * How a collection's template prints headings (core's Seo\RenderProfile),
 * the same for the writer's prompt, the SEO pass and the planner: the
 * profile the Preview tab's renders stored, else what the collection's
 * published entries show, else the default (the title is the H1).
 */
class HeadingProfiles
{
    /** @var array<string, RenderProfile> */
    private array $resolved = [];

    public function __construct(
        private FileRenderProfiles $store,
        private EntryLayouts $entries,
        private BardDialect $dialect,
    ) {}

    /**
     * @param  array<int, EntryData>|null  $studied  The entries already read for the pattern, when there are.
     */
    public function for(ContentType $type, Blueprint $blueprint, Schema $schema, ?array $studied = null, ?string $site = null): RenderProfile
    {
        $site ??= Site::default()->handle();
        $key = FileRenderProfiles::key($type->group, $blueprint->handle(), $site);

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $stored = $this->store->for($type->group, $blueprint->handle(), $site);

        if ($stored === null || ! $stored->rendered()) {
            $studied ??= $this->entries->studied($type->group, $type->variant, $type->where, $type->examples);
        }

        return $this->resolved[$key] = RenderProfile::resolve($key, $stored, $schema, $studied ?? [], $this->dialect, self::label($type->group));
    }

    /** What editors call the collection, for the developer note. */
    public static function label(string $collection): string
    {
        return (string) (Collection::findByHandle($collection)?->title() ?? $collection);
    }

    /** Forget what was resolved, after a render changed a profile. */
    public function forget(): void
    {
        $this->resolved = [];
    }
}
