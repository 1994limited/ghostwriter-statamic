<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Blueprints\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Contracts\EntryWriter;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Support\Str;

/**
 * The default writer. It works on any collection because it takes its
 * instructions from the collection's blueprint: a single Bard body, a page
 * builder of blocks, or anything between.
 */
class SchemaEntryWriter implements EntryWriter
{
    public function __construct(
        private SchemaReader $reader,
        private PatternFinder $patterns,
        private EntryBuilder $builder,
    ) {}

    public function write(Draft $draft, ContentType $type, ?User $user = null): Entry
    {
        $collection = $type->statamicCollection()
            ?? throw new InvalidArgumentException("The collection \"{$type->collection}\" no longer exists.");

        $blueprint = $type->statamicBlueprint();
        $schema = $this->reader->read($blueprint);

        $built = $this->builder->build($draft->data, $schema, $this->patterns->find($collection->handle(), $schema, $type->blueprint, $type->where, $type->examples), $type->defaults);

        $data = $built['data'];
        $data['title'] = $draft->title();

        // A single-author field is set to whoever asked for the piece.
        $author = $blueprint->field('author');

        if ($user && $author && $author->type() === 'users' && ! isset($data['author'])) {
            $data['author'] = $user->id();
        }

        $entry = Entries::make()
            ->collection($collection->handle())
            ->blueprint($blueprint->handle())
            ->slug($this->uniqueSlug($collection->handle(), $draft->title()))
            ->published(false)
            ->data($data);

        if ($collection->dated()) {
            $entry->date(now());
        }

        $entry->save();

        return $entry;
    }

    /**
     * A second draft on the same subject must never overwrite an existing page.
     */
    private function uniqueSlug(string $collection, string $title): string
    {
        $base = Str::slug($title) ?: 'untitled';
        $slug = $base;
        $n = 2;

        while (Entries::query()->where('collection', $collection)->where('slug', $slug)->count() > 0) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
