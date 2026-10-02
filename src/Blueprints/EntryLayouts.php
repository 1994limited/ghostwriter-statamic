<?php

namespace NineteenNinetyFour\Ghostwriter\Blueprints;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ghostwriter\Core\Layout\BuiltEntry;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseResult;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseRules;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Layout\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Testing\LayoutLog;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;

/**
 * Core's layout algorithms on a collection's entries. Which entries are
 * studied, and how they are read, is Statamic's and stays here; what is
 * learned from them (the usual blocks, the house defaults and style, the
 * kinds of entry) and how a draft becomes entry data is core's.
 *
 * Each result is written to the layout log when a test run records one,
 * to compare two runs (docs: core's layout.md).
 */
class EntryLayouts
{
    public function __construct(private Layouts $layouts) {}

    /**
     * How a collection's entries are put together: from the entries picked
     * by hand when there are any, which are then the whole evidence (a
     * collection such as Pages holds several kinds of page, and only a
     * person knows which ones a type should be modelled on); otherwise from
     * the newest published entries, narrowed to those matching `where`
     * while any do.
     *
     * @param  array<string, mixed>  $where  Field => value an entry must have (or contain, for lists).
     * @param  array<int, string>  $examples  Entry IDs to learn from instead.
     */
    public function pattern(Schema $schema, string $collection, ?string $blueprint = null, array $where = [], array $examples = []): Pattern
    {
        $picked = collect($examples)->map(fn (string $id) => Entries::find($id))->filter()->values();

        // Where an entry sits is only for naming kinds, so is not looked up.
        $read = fn (Entry $entry) => self::entryData($entry, parent: false);

        $entries = $picked->isNotEmpty()
            ? $picked->map($read)->all()
            : PatternFinder::choose($this->published($collection, $blueprint)->map($read)->all(), $where);

        $pattern = $this->layouts->patterns()->find($schema, $entries);
        LayoutLog::record('pattern', $pattern);

        return $pattern;
    }

    /**
     * The kinds of entry the collection holds, found from how every
     * published entry is built.
     *
     * @return array<int, array{label: string, count: int, examples: array<int, int|string>, titles: array<int, string>, blocks: array<int, string>}>
     */
    public function kinds(Schema $schema, string $collection, ?string $blueprint = null): array
    {
        // Without a page builder every entry is built the same way, and
        // there is no need to read them.
        $builder = collect($schema->fields)->contains(fn (Field $field) => $field->kind === Kind::Blocks);
        $entries = $builder ? $this->published($collection, $blueprint)->map(fn (Entry $entry) => self::entryData($entry))->all() : [];

        $kinds = $this->layouts->kinds()->find($schema, $entries);
        LayoutLog::record('kinds', $kinds);

        return array_map(fn ($kind) => $kind->toArray(), $kinds);
    }

    /**
     * The fields and the entries to follow, as the Studio shows them.
     */
    public function layout(Schema $schema, Pattern $pattern): Layout
    {
        $layout = Layout::fromSchema($schema, $pattern, $this->layouts->describer());
        LayoutLog::record('describe', $layout->fields);

        return $layout;
    }

    /**
     * A draft as the data an entry stores. Without a pattern (revising an
     * existing entry) nothing the collection usually has is added.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $defaults
     */
    public function build(array $draft, Schema $schema, ?Pattern $pattern = null, array $defaults = []): BuiltEntry
    {
        $built = $this->layouts->builder()->build($draft, $schema, $pattern, $defaults);
        LayoutLog::record('build', $built);

        return $built;
    }

    /**
     * A new entry's data with the house style applied. Without the entry's
     * ID, links to itself wait for linkToSelf().
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data, Schema $schema, HouseRules $house, ?string $id, string $title): HouseResult
    {
        $result = $this->layouts->houseStyle()->apply($data, $schema, $house, $id, $title);
        LayoutLog::record('apply', $result);

        return $result;
    }

    /**
     * Once the entry exists: the links to itself the house style held back.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function linkToSelf(array $data, Schema $schema, HouseRules $house, string $id, string $title): array
    {
        $linked = $this->layouts->houseStyle()->linkToSelf($data, $schema, $house, $id, $title);
        LayoutLog::record('linkToSelf', $linked);

        return $linked;
    }

    /**
     * An entry as core reads it, with the page it sits under unless that is
     * left out. The site's home page, which every top-level page sits
     * under, is no parent worth naming.
     */
    public static function entryData(Entry $entry, bool $parent = true): EntryData
    {
        $parent = $parent ? $entry->parent() : null;
        $parent = $parent && ! $parent->isRoot() ? $parent : null;

        return new EntryData(
            values: $entry->data()->all(),
            id: (string) $entry->id(),
            parentId: $parent?->id(),
            parentTitle: $parent ? (string) $parent->title() : null,
        );
    }

    /**
     * A collection's published entries (of one blueprint, when given), newest first.
     *
     * @return Collection<int, Entry>
     */
    private function published(string $collection, ?string $blueprint): Collection
    {
        return Entries::query()
            ->where('collection', $collection)
            ->where('published', true)
            ->get()
            ->when($blueprint, fn ($all) => $all->filter(fn (Entry $entry) => $entry->blueprint()?->handle() === $blueprint))
            ->sortByDesc(fn (Entry $entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->values();
    }
}
