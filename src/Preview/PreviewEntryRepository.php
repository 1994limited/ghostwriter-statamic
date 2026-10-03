<?php

namespace NineteenNinetyFour\Ghostwriter\Preview;

use Closure;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Entries\EntryRepository;

/**
 * The entry repository during a Ghostwriter preview render: whatever
 * repository is bound (the Stache, or the Eloquent driver), with `find()`
 * answering the previewed entry for its id.
 *
 * Live Preview's substitution covers lookups by URI and lists of entries,
 * but tags that load the current entry by id (`collection:next`,
 * `previous`, `older` and `newer`) go straight to the store, where an
 * unsaved entry isn't, and fail. The tags pass the id as a `Value`, so it
 * is compared as a string. Bound only for the request that renders a
 * Ghostwriter preview (GhostwriterPreviewResponse).
 */
class PreviewEntryRepository implements EntryRepository
{
    public function __construct(private EntryRepository $inner, private Entry $previewed) {}

    public function inner(): EntryRepository
    {
        return $this->inner;
    }

    public function find($id)
    {
        return $this->previewed($id) ?? $this->inner->find($id);
    }

    public function findOrFail($id)
    {
        return $this->previewed($id) ?? $this->inner->findOrFail($id);
    }

    public function findOrMake($id)
    {
        return $this->previewed($id) ?? $this->inner->findOrMake($id);
    }

    public function findOr($id, Closure $callback)
    {
        return $this->previewed($id) ?? $this->inner->findOr($id, $callback);
    }

    public function all()
    {
        return $this->inner->all();
    }

    public function whereCollection(string $handle)
    {
        return $this->inner->whereCollection($handle);
    }

    public function whereInCollection(array $handles)
    {
        return $this->inner->whereInCollection($handles);
    }

    public function findByUri(string $uri, ?string $site = null)
    {
        return $this->inner->findByUri($uri, $site);
    }

    public function make()
    {
        return $this->inner->make();
    }

    public function query()
    {
        return $this->inner->query();
    }

    public function save($entry)
    {
        return $this->inner->save($entry);
    }

    public function delete($entry)
    {
        return $this->inner->delete($entry);
    }

    public function createRules($collection, $site)
    {
        return $this->inner->createRules($collection, $site);
    }

    public function updateRules($collection, $entry)
    {
        return $this->inner->updateRules($collection, $entry);
    }

    /**
     * Everything else the bound repository offers: substitute(),
     * applySubstitutions(), updateUris() and the rest.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    private function previewed(mixed $id): ?Entry
    {
        if ($id === null || is_array($id)) {
            return null;
        }

        return (string) $id === (string) $this->previewed->id() ? $this->previewed : null;
    }
}
