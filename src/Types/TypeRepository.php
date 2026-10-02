<?php

namespace NineteenNinetyFour\Ghostwriter\Types;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindStore;
use NineteenNinetyFour\Ghostwriter\Settings;
use Statamic\Contracts\Entries\Collection as EntryCollection;
use Statamic\Facades\Collection as Collections;
use Statamic\Fields\Blueprint;

/**
 * The kinds of content Ghostwriter writes (core's ContentType), as
 * Statamic sees them: the ones it has been taught, kept by the KindStore,
 * and the general one every collection has; the collections it writes
 * for; and each kind's collection, blueprint and questionnaire.
 */
class TypeRepository
{
    public function __construct(private KindStore $store) {}

    /**
     * @return Collection<string, ContentType>
     */
    public function all(): Collection
    {
        return collect($this->store->all())->keyBy(fn (ContentType $type) => $type->handle);
    }

    public function find(string $handle): ?ContentType
    {
        if (str_starts_with($handle, ContentType::GENERIC)) {
            $collection = Collections::findByHandle(substr($handle, strlen(ContentType::GENERIC)));

            return $collection ? self::generic($collection) : null;
        }

        return $this->store->find($handle);
    }

    /**
     * The kind every collection has without being taught anything: a
     * general brief, and no fixed recipe.
     */
    public static function generic(EntryCollection $collection): ContentType
    {
        return ContentType::generic(Format::Statamic, $collection->handle(), (string) $collection->title());
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
            $generic = self::generic($found);
            $types->put($generic->handle, $generic);
        }

        return $types;
    }

    /**
     * @return Collection<string, ContentType>
     */
    public function forCollection(string $collection): Collection
    {
        return $this->all()->filter(fn (ContentType $type) => $type->group === $collection);
    }

    /**
     * A handle for a new kind from its title, kept apart from any kind
     * already saved: a second "Guide" becomes guide-2.
     */
    public function handleFor(string $title, string $fallback): string
    {
        return ContentType::handleFor(Format::Statamic, $title, $fallback, $this->all()->keys()->all());
    }

    public function save(ContentType $type): ContentType
    {
        return $this->store->save($type);
    }

    public function delete(ContentType $type): void
    {
        $this->store->delete($type->handle);
    }

    /**
     * A kind made from a definition, written in the usual order when it is
     * saved (rather than in the order the definition was put together).
     *
     * @param  array<string, mixed>  $definition
     */
    public static function make(string $handle, array $definition): ContentType
    {
        $read = ContentType::fromArray($definition, Format::Statamic, $handle);

        return new ContentType(Format::Statamic, $read->handle, $read->title, $read->description, $read->group, $read->questions, $read->guidance, $read->checklist, $read->variant, $read->where, $read->defaults, $read->examples);
    }

    /**
     * The collections Ghostwriter writes into: those chosen in its settings,
     * or every collection when none is chosen.
     *
     * @return Collection<int, EntryCollection>
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

    public static function collectionOf(ContentType $type): ?EntryCollection
    {
        return Collections::findByHandle($type->group);
    }

    /**
     * The blueprint a kind is written with: its own, or the collection's first.
     */
    public static function blueprintOf(ContentType $type): ?Blueprint
    {
        $collection = self::collectionOf($type);

        if (! $collection) {
            return null;
        }

        return $type->variant ? $collection->entryBlueprint($type->variant) : $collection->entryBlueprint();
    }

    /**
     * What the questionnaire screen needs.
     *
     * @return array<string, mixed>
     */
    public static function forQuestionnaire(ContentType $type): array
    {
        return [
            'handle' => $type->handle,
            'title' => $type->title,
            'description' => $type->description,
            'questions' => $type->questions,
            'examples' => $type->examples,
            'generic' => $type->isGeneric(),
        ];
    }

    /**
     * Laravel validation rules for the questionnaire.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(ContentType $type): array
    {
        $rules = [];

        foreach ($type->questions as $question) {
            $rules['answers.'.$question['handle']] = [
                ($question['required'] ?? false) ? 'required' : 'nullable',
                'string',
                'max:20000',
            ];
        }

        return $rules;
    }
}
