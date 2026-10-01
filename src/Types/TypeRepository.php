<?php

namespace NineteenNinetyFour\Ghostwriter\Types;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Settings;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\YAML;

/**
 * Content types are YAML files in the project, one per kind of content, so
 * they are versioned with the site and can be edited by hand. They are
 * created by analysing a collection and are the project's to change after.
 */
class TypeRepository
{
    /**
     * @return Collection<string, ContentType>
     */
    public function all(): Collection
    {
        if (! File::isDirectory($this->directory())) {
            return collect();
        }

        return collect(File::files($this->directory()))
            ->filter(fn ($file) => $file->getExtension() === 'yaml')
            ->mapWithKeys(function ($file) {
                $handle = $file->getFilenameWithoutExtension();

                return [$handle => ContentType::fromArray($handle, (array) YAML::parse(File::get($file->getPathname())))];
            })
            ->sortBy(fn (ContentType $type) => $type->title);
    }

    public function find(string $handle): ?ContentType
    {
        if (str_starts_with($handle, ContentType::GENERIC)) {
            $collection = Collections::findByHandle(substr($handle, strlen(ContentType::GENERIC)));

            return $collection ? ContentType::generic($collection) : null;
        }

        return $this->all()->get($handle);
    }

    /**
     * Everything that can be written in a collection: the kinds it has been
     * taught, then the general one that is always there.
     *
     * @return Collection<string, ContentType>
     */
    public function offeredFor(string $collection): Collection
    {
        $types = $this->forCollection($collection);

        if ($found = Collections::findByHandle($collection)) {
            $generic = ContentType::generic($found);
            $types->put($generic->handle, $generic);
        }

        return $types;
    }

    /**
     * @return Collection<string, ContentType>
     */
    public function forCollection(string $collection): Collection
    {
        return $this->all()->filter(fn (ContentType $type) => $type->collection === $collection);
    }

    /**
     * A handle for a new type from its title, kept apart from any type
     * already saved: a second "Guide" becomes guide-2.
     */
    public function handleFor(string $title, string $fallback): string
    {
        $base = Str::slug($title) ?: $fallback;
        $handle = $base;
        $n = 2;

        while ($this->all()->has($handle)) {
            $handle = $base.'-'.$n++;
        }

        return $handle;
    }

    public function save(ContentType $type): ContentType
    {
        File::ensureDirectoryExists($this->directory());
        File::put($this->directory().'/'.$type->handle.'.yaml', YAML::dump($type->toArray()));

        return $type;
    }

    public function delete(ContentType $type): void
    {
        File::delete($this->directory().'/'.$type->handle.'.yaml');
    }

    /**
     * The collections Ghostwriter writes into: those chosen in its settings,
     * or every collection when none is chosen.
     *
     * @return Collection<int, \Statamic\Contracts\Entries\Collection>
     */
    public function collections(): Collection
    {
        $handles = app(Settings::class)->collections();

        return Collections::all()
            ->filter(fn ($collection) => $handles === [] || in_array($collection->handle(), $handles, true))
            ->values();
    }

    public function enabled(string $collection): bool
    {
        return $this->collections()->contains(fn ($item) => $item->handle() === $collection);
    }

    private function directory(): string
    {
        return (string) config('ghostwriter.types_path');
    }
}
