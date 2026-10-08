<?php

namespace NineteenNinetyFour\Ghostwriter\Suggest;

use DateTimeImmutable;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Links;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkCandidates;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexedParagraph;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkLookup;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\RowKind;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Shingles;
use NineteenNinetyFour\Ghostwriter\Storage\FileRevisitStore;
use NineteenNinetyFour\Ghostwriter\Storage\JsonFiles;
use Statamic\Facades\Site;
use Throwable;

/**
 * The site's pages as Suggest edits and the SEO layer compare a page with
 * them, kept beside the revisit shards (`revisit/{site}/{group}.index.json`,
 * one row per page, core's IndexRow):
 *
 * - full rows: entries of Ghostwriter's collections, with their
 *   paragraphs' shingles. Duplicates (Overlaps) look for paragraphs
 *   sharing shingles; the review's digest takes the entries whose titles
 *   and summaries share most words with the page.
 * - link rows: every other routable page (collections with a route,
 *   taxonomy terms with text of their own), only for related(): no
 *   shingles, no revisit row.
 *
 * Above LinkCandidates::STEM_INDEX_ABOVE rows a site has a stem index
 * (`revisit/{site}/_stems.json`), written by the daily pass, that narrows
 * the rows related() scores. No model, and nothing read from the Stache
 * when asked.
 */
class FileEntryIndex implements EntryIndex, LinkIndex, LinkLookup
{
    use JsonFiles;

    public const STEMS = '_stems.json';

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
            if ($key === $except->key() || self::isLink($entry)) {
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
            if ($key === $entry->key() || self::isLink($other)) {
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
            is_string($row[4]['type'] ?? null) ? $row[4]['type'] : '',
        ), array_slice($scored, 0, max(0, $limit)));
    }

    public function related(string $text, string $group, int|string|null $site = null, ?EntryRef $except = null, int $limit = LinkCandidates::LIMIT, array $linked = [], ?DateTimeImmutable $now = null): array
    {
        $site ??= Site::default()->handle();
        $locale = self::locale($site);

        return LinkCandidates::rank($this->candidates($site, $text, $locale, $except), $text, $group, $site, $except, $limit, $linked, $now, $locale);
    }

    /**
     * The row a link already in a draft points at (`statamic://entry::abc`,
     * `entry::abc`, or the page's address), so the writer's links to real
     * pages are kept (LinkGuard).
     */
    public function linkRow(string $href, int|string|null $site = null): ?IndexRow
    {
        $site ??= Site::default()->handle();

        return LinkCandidates::rowFor($this->rows($site), $href, $site);
    }

    /**
     * Keeps an entry's full row: what the link source says of it, and its
     * paragraphs' shingles from what the checks read of it. Without a row
     * (older callers) the title, address and summary given are kept.
     */
    public function put(EntryRef $ref, string $title, ?string $url, CheckContext $context, string $summary = '', ?IndexRow $row = null): void
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

        $summary = mb_substr(trim($summary !== '' ? $summary : $first), 0, DigestEntry::SUMMARY);
        $row ??= IndexRow::make($ref, IndexScope::Full, $title, $url, link: 'entry::'.$ref->id, updated: now()->toAtomString(), indexed: now()->toAtomString(), locale: self::locale($ref->site));

        // The row's own summary (an SEO description, a summary field) first; else the first paragraph.
        if ($row->summary === '' && $summary !== '') {
            $row = IndexRow::make($ref, IndexScope::Full, $row->title, $row->url, $summary, $row->type, $row->kind, $row->liveFrom, $row->liveUntil, $row->noindex, $row->key, $row->link, $row->updated, $row->published, $row->indexed, self::locale($ref->site));
        }

        // Where the page links on the site, so pages linking to it rank higher for it (LinkCandidates).
        $row = $row->withRelations(links: Links::in($context, $context->gaps->hosts)['internal']);
        $data = [...$row->withScope(IndexScope::Full)->toArray(), 'entry' => $ref->toArray(), 'title' => $title, 'url' => $url, 'paragraphs' => $paragraphs];

        $this->change($ref->site, $ref->group, function (array $rows) use ($ref, $data) {
            $rows[$ref->key()] = $data;

            return $rows;
        });
    }

    /**
     * Keeps link rows, one write per file. A full row of the same page is
     * replaced: its group isn't Ghostwriter's any more.
     *
     * @param  iterable<IndexRow>  $rows
     */
    public function putLinks(iterable $rows): void
    {
        $byFile = [];

        foreach ($rows as $row) {
            $byFile[(string) $row->entry->site."\0".$row->entry->group][] = $row;
        }

        foreach ($byFile as $list) {
            $first = $list[0];

            $this->change($first->entry->site, $first->entry->group, function (array $stored) use ($list) {
                foreach ($list as $row) {
                    $stored[$row->entry->key()] = $row->withScope(IndexScope::Link)->toArray();
                }

                return $stored;
            });
        }
    }

    public function forget(EntryRef $ref): void
    {
        $this->change($ref->site, $ref->group, function (array $rows) use ($ref) {
            unset($rows[$ref->key()]);

            return $rows;
        });
    }

    /**
     * Forgets rows of a site by key, one write per file.
     *
     * @param  list<string>  $keys
     */
    public function forgetKeys(int|string|null $site, array $keys): void
    {
        $byGroup = [];
        $all = $this->entries($site);

        foreach ($keys as $key) {
            if (isset($all[$key])) {
                $byGroup[(string) ($all[$key]['entry']['group'] ?? '')][] = $key;
            }
        }

        foreach ($byGroup as $group => $list) {
            $this->change($site, (string) $group, function (array $rows) use ($list) {
                foreach ($list as $key) {
                    unset($rows[$key]);
                }

                return $rows;
            });
        }
    }

    public function row(EntryRef $ref): ?IndexRow
    {
        $data = $this->entries($ref->site)[$ref->key()] ?? null;

        return is_array($data) ? self::rowOf($data, $ref->site) : null;
    }

    /**
     * Every row of a site, both scopes, by key.
     *
     * @return \Generator<string, IndexRow>
     */
    public function rows(int|string|null $site): \Generator
    {
        foreach ($this->entries($site) as $key => $data) {
            $row = self::rowOf($data, $site);

            if ($row !== null) {
                yield $key => $row;
            }
        }
    }

    /**
     * What the daily pass compares with the site's pages: each row's
     * group, scope, page date and when it was written.
     *
     * @return array<string, array{group: string, scope: string, updated: ?string, indexed: ?string}>
     */
    public function meta(int|string|null $site): array
    {
        $meta = [];

        foreach ($this->entries($site) as $key => $data) {
            $meta[$key] = [
                'group' => (string) ($data['entry']['group'] ?? ''),
                'scope' => self::isLink($data) ? IndexScope::Link->value : IndexScope::Full->value,
                'updated' => is_string($data['updated'] ?? null) ? $data['updated'] : null,
                'indexed' => is_string($data['indexed'] ?? null) ? $data['indexed'] : null,
            ];
        }

        return $meta;
    }

    /** Forget what was read, so the next read sees what another instance (or process) wrote. */
    public function fresh(): static
    {
        $this->loaded = [];

        return $this;
    }

    /** How many rows a site has. */
    public function count(int|string|null $site): int
    {
        return count($this->entries($site));
    }

    /**
     * Writes a site's stem index when it has more than
     * LinkCandidates::STEM_INDEX_ABOVE rows, and removes it otherwise.
     */
    public function writeStems(int|string|null $site): void
    {
        $path = FileRevisitStore::path().'/'.self::name($site).'/'.self::STEMS;

        if ($this->count($site) <= LinkCandidates::STEM_INDEX_ABOVE) {
            File::delete($path);

            return;
        }

        $stems = [];

        foreach ($this->rows($site) as $key => $row) {
            foreach (LinkCandidates::indexKeys($row) as $stem) {
                $stems[$stem][] = $key;
            }
        }

        $this->writeJson($path, ['built' => now()->toAtomString(), 'stems' => $stems], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The rows related() scores: every row of a small site; on a big one,
     * the rows the stem index says may share a stem with the draft, key
     * pages, the page's own row (what it's filed under and links to) and
     * rows written since the index was.
     *
     * @return iterable<IndexRow>
     */
    private function candidates(int|string|null $site, string $text, ?string $locale, ?EntryRef $except = null): iterable
    {
        $all = $this->entries($site);
        $stems = count($all) > LinkCandidates::STEM_INDEX_ABOVE ? $this->readJson(FileRevisitStore::path().'/'.self::name($site).'/'.self::STEMS) : null;

        if (! is_array($stems) || ! is_array($stems['stems'] ?? null)) {
            return $this->rows($site);
        }

        $keys = [];

        foreach (LinkCandidates::lookupKeys($text, $locale) as $stem) {
            foreach (is_array($stems['stems'][$stem] ?? null) ? $stems['stems'][$stem] : [] as $key) {
                $keys[(string) $key] = true;
            }
        }

        $built = is_string($stems['built'] ?? null) ? $stems['built'] : '';
        $rows = [];

        foreach ($all as $key => $data) {
            if (isset($keys[$key]) || (string) ($data['indexed'] ?? '') > $built || ($data['key'] ?? false) === true || $key === $except?->key()) {
                $row = self::rowOf($data, $site);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function rowOf(array $data, int|string|null $site): ?IndexRow
    {
        $row = IndexRow::fromArray($data, self::locale($site));

        // A row written before link rows existed: a Ghostwriter entry, linked to by reference.
        if ($row !== null && $row->link === null && $row->kind === RowKind::Entry) {
            $array = $row->toArray();
            $array['link'] = 'entry::'.$row->entry->id;

            return IndexRow::fromArray($array);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function isLink(array $data): bool
    {
        return ($data['scope'] ?? null) === IndexScope::Link->value;
    }

    /** The locale stems are worked out in for a site: the checks' language. */
    private static function locale(int|string|null $site): ?string
    {
        try {
            return app(EntryChecks::class)->language($site === null ? null : (string) $site);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  callable(array<string, array<string, mixed>>): array<string, array<string, mixed>>  $change
     */
    private function change(int|string|null $site, string $group, callable $change): void
    {
        $path = $this->path($site, $group);

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
     * Every indexed page of a site, by key.
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
