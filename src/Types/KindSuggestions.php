<?php

namespace NineteenNinetyFour\Ghostwriter\Types;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Core\Text\Utf8;
use Statamic\Contracts\Entries\Collection;
use Statamic\Facades\Entry;

/**
 * Kinds of content Ghostwriter has suggested for each collection, waiting
 * for a person to say which are worth learning. Working state, kept beside
 * the sessions.
 *
 * A collection is checked when someone asks, and by itself (when the setting
 * is on) the first time it is seen and again once enough has been published
 * there since, so suggestions keep up with the site without a call on every
 * visit.
 */
class KindSuggestions
{
    public const IDLE = 'idle';

    public const WORKING = 'working';

    public const FAILED = 'failed';

    /** New published entries since the last check that make another one worthwhile. */
    public const RECHECK_AFTER = 10;

    /**
     * @return array{status: string, error: ?string, checked_at: ?string, entries: int, suggestions: array<int, array<string, mixed>>, dismissed: array<int, string>}
     */
    public function get(string $collection): array
    {
        return array_merge(
            ['status' => self::IDLE, 'error' => null, 'checked_at' => null, 'entries' => 0, 'suggestions' => [], 'dismissed' => []],
            $this->all()[$collection] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(string $collection, array $changes): void
    {
        $all = $this->all();
        $all[$collection] = array_merge($this->get($collection), $changes);

        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode(Utf8::scrub($all), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<int, array<string, mixed>>  $suggestions
     */
    public function store(string $collection, array $suggestions, int $entries): void
    {
        $this->update($collection, [
            'status' => self::IDLE,
            'error' => null,
            'checked_at' => Carbon::now()->toIso8601String(),
            'entries' => $entries,
            'suggestions' => array_values(array_map(fn (array $suggestion) => ['id' => Str::lower(Str::random(12))] + $suggestion, $suggestions)),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $collection, string $id): ?array
    {
        foreach ($this->get($collection)['suggestions'] as $suggestion) {
            if ($suggestion['id'] === $id) {
                return $suggestion;
            }
        }

        return null;
    }

    /**
     * Take a suggestion off the list. Turned down, it is remembered by
     * title, so it is not suggested again.
     */
    public function remove(string $collection, string $id, bool $dismissed = false): void
    {
        $state = $this->get($collection);
        $gone = $this->find($collection, $id);

        $this->update($collection, [
            'suggestions' => array_values(array_filter($state['suggestions'], fn (array $suggestion) => $suggestion['id'] !== $id)),
            'dismissed' => $dismissed && $gone ? array_values(array_unique([...$state['dismissed'], $gone['title']])) : $state['dismissed'],
        ]);
    }

    /**
     * Whether a collection should be checked without being asked: never
     * looked at, or with enough published since the last look.
     */
    public function due(Collection $collection): bool
    {
        $state = $this->get($collection->handle());

        if ($state['status'] !== self::IDLE) {
            return false;
        }

        $published = Entry::query()->where('collection', $collection->handle())->where('published', true)->count();

        if ($published < 2) {
            return false;
        }

        return $state['checked_at'] === null || $published >= $state['entries'] + self::RECHECK_AFTER;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function all(): array
    {
        return File::exists($this->path()) ? (array) json_decode((string) File::get($this->path()), true) : [];
    }

    private function path(): string
    {
        return dirname((string) config('ghostwriter.sessions_path')).'/kinds.json';
    }
}
