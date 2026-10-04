<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Contracts\EntryWriter;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\MarkerResolver;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
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
        private EntryLayouts $layouts,
        private HouseFinish $finish,
    ) {}

    public function write(Draft $draft, ContentType $type, ?User $user = null): Entry
    {
        $collection = TypeRepository::collectionOf($type)
            ?? throw new InvalidArgumentException("The collection \"{$type->group}\" no longer exists.");

        $blueprint = TypeRepository::blueprintOf($type);
        $schema = $this->reader->schema($blueprint);

        $pattern = $this->layouts->pattern($schema, $collection->handle(), $type->variant, $type->where, $type->examples);
        $built = $this->layouts->build(DraftValues::words($draft->data), $schema, $pattern, $type->defaults);

        $data = $this->finish->finish($built->data, $schema, $pattern, null, $draft->title())['data'];
        // Links chosen from the preview for fields the draft doesn't hold.
        $data = MarkerResolver::withChosenLinks($data, MarkerResolver::chosenLinks($draft->data));
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

        // Now it has an ID, the links the house style makes to the page itself.
        $linked = $this->finish->linkToSelf($entry->data()->all(), $schema, $pattern, (string) $entry->id(), $draft->title());

        if ($linked !== $entry->data()->all()) {
            $entry->data($linked)->save();
        }

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
