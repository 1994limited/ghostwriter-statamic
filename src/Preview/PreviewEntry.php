<?php

namespace NineteenNinetyFour\Ghostwriter\Preview;

use Illuminate\Support\Str;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Routing\UrlBuilder;

/**
 * A new entry the page preview renders, never saved. It has a preview-only
 * id (`gw-preview-<ulid>`), since Statamic's Live Preview finds the item it
 * stands in for by id and fails on none. An entry in a structured
 * collection isn't in the tree, so Statamic gives it no URI and the
 * preview would show the home page; this one answers with the URI it will
 * have: its parent's (the form's parent, or the root) plus its slug, by the
 * collection's route.
 *
 * Registered with the cache's allow-list (ServiceProvider), because Live
 * Preview keeps the item in the cache between the CP and the frame.
 */
class PreviewEntry extends Entry
{
    public const PREFIX = 'gw-preview-';

    /** The parent entry's id from the form, in a structured collection. */
    protected ?string $previewParent = null;

    public static function newId(): string
    {
        return self::PREFIX.Str::lower((string) Str::ulid());
    }

    public static function isPreviewId(mixed $id): bool
    {
        return is_string($id) && str_starts_with($id, self::PREFIX);
    }

    public function previewParent(?string $id): static
    {
        $this->previewParent = $id !== '' ? $id : null;

        return $this;
    }

    public function uri()
    {
        $uri = parent::uri();

        if ($uri !== null || ! $this->route() || ! ($structure = $this->structure())) {
            return $uri;
        }

        // As Statamic's Page::uri() builds it for an entry in the tree.
        $root = $structure->in($this->locale())?->root()['entry'] ?? null;
        $parent = $this->previewParent !== null && $this->previewParent !== $root ? Entries::find($this->previewParent) : null;
        $parentUri = Str::replace(['.html', '.htm'], '', (string) $parent?->uri());

        return app(UrlBuilder::class)
            ->content($this)
            ->merge([
                'parent_uri' => $parentUri === '/' ? '' : $parentUri,
                'slug' => $this->slug(),
                'depth' => $parent ? 2 : 1,
                'is_root' => false,
            ])
            ->build($this->route());
    }
}
