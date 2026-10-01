<?php

namespace NineteenNinetyFour\Ghostwriter\Content;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ghostwriter\Settings;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry as Entries;

/**
 * Gathers a representative sample of the site's published writing for the
 * voice analyst to read. Newest entries come first, spread across the
 * collections, and everything is capped so one scan is one affordable request.
 */
class ContentScanner
{
    public function __construct(private ProseExtractor $extractor) {}

    /**
     * Collections available to scan, with how many published entries each has.
     *
     * @return Collection<int, array{handle: string, title: string, entries: int, selected: bool}>
     */
    public function collections(): Collection
    {
        $selected = $this->selectedHandles();

        return Collections::all()->map(fn ($collection) => [
            'handle' => $collection->handle(),
            'title' => $collection->title(),
            'entries' => Entries::query()->where('collection', $collection->handle())->where('published', true)->count(),
            'selected' => $selected === [] || in_array($collection->handle(), $selected, true),
        ])->values();
    }

    /**
     * @param  array<int, string>|null  $collections  Handles to read; null uses the configured set.
     * @return Collection<int, array{title: string, collection: string, url: ?string, text: string}>
     */
    public function samples(?array $collections = null): Collection
    {
        $handles = $collections ?? $this->selectedHandles();

        if ($handles === []) {
            $handles = Collections::handles()->all();
        }

        $perEntry = (int) config('ghostwriter.voice.max_chars_per_entry', 6000);
        $budget = (int) config('ghostwriter.voice.max_chars', 90000);
        $limit = (int) config('ghostwriter.voice.max_entries', 24);

        $samples = collect();

        foreach ($this->interleaved($handles) as $entry) {
            if ($samples->count() >= $limit || $budget <= 0) {
                break;
            }

            $text = $this->extractor->fromEntry($entry);

            // Too little to learn a voice from: a contact page, a stub.
            if (mb_strlen($text) < 400) {
                continue;
            }

            $text = mb_substr($text, 0, min($perEntry, $budget));
            $budget -= mb_strlen($text);

            $samples->push([
                'title' => (string) $entry->get('title'),
                'collection' => $entry->collectionHandle(),
                'url' => $entry->url(),
                'text' => $text,
            ]);
        }

        return $samples;
    }

    /**
     * Newest-first from each collection, taken one at a time in turn, so a
     * large collection cannot crowd out a small one.
     *
     * @param  array<int, string>  $handles
     * @return Collection<int, Entry>
     */
    private function interleaved(array $handles): Collection
    {
        $queues = collect($handles)->map(fn (string $handle) => Entries::query()
            ->where('collection', $handle)
            ->where('published', true)
            ->get()
            ->sortByDesc(fn (Entry $entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->values());

        $longest = $queues->max(fn (Collection $queue) => $queue->count()) ?? 0;
        $ordered = collect();

        for ($i = 0; $i < $longest; $i++) {
            foreach ($queues as $queue) {
                if ($queue->has($i)) {
                    $ordered->push($queue->get($i));
                }
            }
        }

        return $ordered;
    }

    /**
     * @return array<int, string>
     */
    private function selectedHandles(): array
    {
        return app(Settings::class)->voiceCollections();
    }
}
