<?php

namespace NineteenNinetyFour\Ghostwriter\Suggest;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexedParagraph;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Shingles;
use NineteenNinetyFour\Ghostwriter\Storage\FileRevisitStore;
use NineteenNinetyFour\Ghostwriter\Storage\JsonFiles;

/**
 * The site's entries as Suggest edits compares pages with them: each
 * entry's title, address, a short summary and its paragraphs' shingles,
 * kept beside the revisit shards (`revisit/{site}/{collection}.index.json`)
 * and written when the entry is saved. Duplicates (Overlaps) look for
 * paragraphs sharing shingles; the review's digest takes the entries
 * whose titles and summaries share most words with the page.
 *
 * No model, and nothing read from the Stache when asked.
 */
class FileEntryIndex implements EntryIndex
{
    use JsonFiles;

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $loaded = [];

    public function __construct(private Lock $lock) {}

    public function sharing(array $shingles, EntryRef $except, int $limit = 5): array
    {
        if ($shingles === []) {
            return [];
        }

        $found = [];

        foreach ($this->entries($except->site) as $key => $entry) {
            if ($key === $except->key()) {
                continue;
            }

            foreach (is_array($entry['paragraphs'] ?? null) ? $entry['paragraphs'] : [] as $paragraph) {
                $paragraph = array_values(array_filter(is_array($paragraph) ? $paragraph : [], 'is_int'));
                $shared = count(array_intersect($shingles, $paragraph));

                if ($shared > 0) {
                    $found[] = [$shared, new IndexedParagraph(EntryRef::fromArray($entry['entry']), (string) ($entry['title'] ?? ''), $paragraph, is_string($entry['url'] ?? null) ? $entry['url'] : null)];
                }
            }
        }

        usort($found, fn (array $a, array $b) => $b[0] <=> $a[0]);

        return array_map(fn (array $pair) => $pair[1], array_slice($found, 0, max(0, $limit)));
    }

    public function nearest(EntryRef $entry, string $text, int $limit = 20): array
    {
        $words = array_unique(NormalisedText::words($text));
        $scored = [];

        foreach ($this->entries($entry->site) as $key => $other) {
            if ($key === $entry->key()) {
                continue;
            }

            $ref = EntryRef::fromArray($other['entry']);
            $theirs = array_unique(NormalisedText::words(($other['title'] ?? '').' '.($other['summary'] ?? '')));
            $shared = count(array_intersect($words, $theirs));

            if ($shared === 0) {
                continue;
            }

            $scored[] = [$ref->group === $entry->group ? 1 : 0, $shared, (string) ($other['title'] ?? ''), $ref, $other];
        }

        usort($scored, fn (array $a, array $b) => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);

        return array_map(fn (array $row) => new DigestEntry(
            $row[3],
            $row[2],
            is_string($row[4]['url'] ?? null) ? $row[4]['url'] : null,
            mb_substr((string) ($row[4]['summary'] ?? ''), 0, DigestEntry::SUMMARY),
            'entry::'.$row[3]->id,
        ), array_slice($scored, 0, max(0, $limit)));
    }

    /**
     * Keeps an entry's title, address, summary and paragraphs' shingles,
     * from what the checks read of it.
     */
    public function put(EntryRef $ref, string $title, ?string $url, CheckContext $context, string $summary = ''): void
    {
        $paragraphs = [];
        $first = '';

        foreach ($context->texts() as $text) {
            foreach (array_keys($text->blocks) as $i) {
                $paragraph = $text->block($i);

                if ($first === '' && count(NormalisedText::words($paragraph)) >= 8) {
                    $first = trim($paragraph);
                }

                if (count(NormalisedText::words($paragraph)) >= Shingles::MIN_WORDS) {
                    $paragraphs[] = Shingles::of($paragraph);
                }
            }
        }

        $row = [
            'entry' => $ref->toArray(),
            'title' => $title,
            'url' => $url,
            'summary' => mb_substr(trim($summary !== '' ? $summary : $first), 0, DigestEntry::SUMMARY),
            'paragraphs' => $paragraphs,
        ];

        $this->change($ref, function (array $rows) use ($ref, $row) {
            $rows[$ref->key()] = $row;

            return $rows;
        });
    }

    public function forget(EntryRef $ref): void
    {
        $this->change($ref, function (array $rows) use ($ref) {
            unset($rows[$ref->key()]);

            return $rows;
        });
    }

    /**
     * @param  callable(array<string, array<string, mixed>>): array<string, array<string, mixed>>  $change
     */
    private function change(EntryRef $ref, callable $change): void
    {
        $path = $this->path($ref->site, $ref->group);

        $this->lock->run('entry-index:'.sha1($path), function () use ($path, $change) {
            $data = $this->readJson($path);
            $rows = $change(is_array($data) ? $data : []);

            if ($rows === []) {
                File::delete($path);
            } else {
                $this->writeJson($path, $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $this->loaded = [];
        });
    }

    /**
     * Every indexed entry of a site, by key.
     *
     * @return array<string, array<string, mixed>>
     */
    private function entries(int|string|null $site): array
    {
        $directory = FileRevisitStore::path().'/'.self::name($site);

        if (! isset($this->loaded[$directory])) {
            $all = [];

            foreach (glob($directory.'/*.index.json') ?: [] as $path) {
                foreach ($this->readJson($path) ?? [] as $key => $row) {
                    if (is_array($row) && is_array($row['entry'] ?? null)) {
                        $all[(string) $key] = $row;
                    }
                }
            }

            $this->loaded[$directory] = $all;
        }

        return $this->loaded[$directory];
    }

    private function path(int|string|null $site, string $group): string
    {
        return FileRevisitStore::path().'/'.self::name($site).'/'.self::name($group).'.index.json';
    }

    private static function name(int|string|null $handle): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $handle), '-');

        return $name === '' ? '_' : $name;
    }
}
