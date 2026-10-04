<?php

namespace NineteenNinetyFour\Ghostwriter\Storage;

use DateTimeImmutable;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Priority;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitRow;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;

/**
 * Content to revisit's rows, as JSON shards: one per site and collection
 * (`revisit/{site}/{collection}.json`, keyed by entry), beside the
 * sessions. A save rewrites one small shard under the file lock; the list
 * loads a site's shards and sorts them in memory, which is quick for the
 * few thousand entries a flat-file site holds. Reverse links are read
 * from each row's `linksTo`.
 */
class FileRevisitStore implements RevisitStore
{
    use JsonFiles;

    /** @var array<string, array<string, array<string, mixed>>> Shards read in this request, by path. */
    private array $shards = [];

    public function __construct(private Lock $lock) {}

    public function put(RevisitRow $row): void
    {
        $path = $this->shard($row->entry->site, $row->entry->group);

        $this->lock->run('revisit:'.$this->name($row->entry->site).':'.$this->name($row->entry->group), function () use ($path, $row) {
            unset($this->shards[$path]);
            $rows = $this->load($path);
            $rows[$row->entry->key()] = $row->toArray();
            $this->write($path, $rows);
        });
    }

    public function get(EntryRef $entry): ?RevisitRow
    {
        $row = $this->load($this->shard($entry->site, $entry->group))[$entry->key()] ?? null;

        return is_array($row) ? RevisitRow::fromArray($row) : null;
    }

    public function forget(EntryRef $entry): void
    {
        $path = $this->shard($entry->site, $entry->group);

        if (! is_file($path)) {
            return;
        }

        $this->lock->run('revisit:'.$this->name($entry->site).':'.$this->name($entry->group), function () use ($path, $entry) {
            unset($this->shards[$path]);
            $rows = $this->load($path);

            if (! array_key_exists($entry->key(), $rows)) {
                return;
            }

            unset($rows[$entry->key()]);
            $this->write($path, $rows);
        });
    }

    public function top(int|string|null $site, ?string $group = null, int $limit = 25, int $offset = 0, array $kinds = [], ?DateTimeImmutable $now = null): array
    {
        $rows = $this->listed($site, $group, $kinds, $now);

        return array_slice($rows, max(0, $offset), max(0, $limit));
    }

    public function count(int|string|null $site, ?string $group = null, array $kinds = [], ?DateTimeImmutable $now = null): int
    {
        return count($this->listed($site, $group, $kinds, $now));
    }

    public function stats(int|string|null $site, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable;
        $stats = ['worth-a-look' => 0];

        foreach ($this->all($site) as $row) {
            if ($row->isSnoozed($now)) {
                continue;
            }

            if ($row->score >= Priority::WORTH_A_LOOK) {
                $stats['worth-a-look']++;
            }

            $kinds = [];

            foreach ($row->reasons as $reason) {
                $kinds[$reason->kind->value] = true;
            }

            foreach (array_keys($kinds) as $kind) {
                $stats[$kind] = ($stats[$kind] ?? 0) + 1;
            }
        }

        return $stats;
    }

    public function linkingTo(string $target, int|string|null $site = null): array
    {
        $refs = [];

        foreach ($this->all($site) as $row) {
            if (in_array($target, $row->linksTo, true)) {
                $refs[] = $row->entry;
            }
        }

        return $refs;
    }

    public function all(int|string|null $site = null): iterable
    {
        $rows = [];

        foreach ($this->shardsOf($site) as $path) {
            foreach ($this->load($path) as $row) {
                if (is_array($row)) {
                    $rows[] = RevisitRow::fromArray($row);
                }
            }
        }

        return $rows;
    }

    /**
     * The list before paging: unsnoozed rows scoring above nothing, highest
     * first, then by title.
     *
     * @param  array<int, string>  $kinds
     * @return list<RevisitRow>
     */
    private function listed(int|string|null $site, ?string $group, array $kinds, ?DateTimeImmutable $now): array
    {
        $now ??= new DateTimeImmutable;
        $rows = [];

        foreach ($this->all($site) as $row) {
            if ($row->score <= 0 || $row->isSnoozed($now) || ($group !== null && $row->entry->group !== $group)) {
                continue;
            }

            if ($kinds !== [] && ! array_filter($row->reasons, fn ($reason) => in_array($reason->kind->value, $kinds, true))) {
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, fn (RevisitRow $a, RevisitRow $b) => [$b->score, mb_strtolower($a->title)] <=> [$a->score, mb_strtolower($b->title)]);

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function shardsOf(int|string|null $site): array
    {
        $pattern = $site === null ? $this->directory().'/*/*.json' : $this->directory().'/'.$this->name($site).'/*.json';

        return array_values(array_filter(glob($pattern) ?: [], fn (string $path) => ! str_ends_with($path, '.index.json')));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(string $path): array
    {
        if (! isset($this->shards[$path])) {
            $data = $this->readJson($path);
            $this->shards[$path] = is_array($data) ? array_filter($data, 'is_array') : [];
        }

        return $this->shards[$path];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function write(string $path, array $rows): void
    {
        if ($rows === []) {
            File::delete($path);
        } else {
            $this->writeJson($path, $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $this->shards[$path] = $rows;
    }

    private function shard(int|string|null $site, string $group): string
    {
        return $this->directory().'/'.$this->name($site).'/'.$this->name($group).'.json';
    }

    /** A site or collection handle as a safe file name; "_" for no site. */
    private function name(int|string|null $handle): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $handle), '-');

        return $name === '' ? '_' : $name;
    }

    private function directory(): string
    {
        return self::path();
    }

    /** Where the shards (and the entry index beside them) are kept. */
    public static function path(): string
    {
        return (string) (config('ghostwriter.revisit_path') ?: dirname((string) config('ghostwriter.sessions_path')).'/revisit');
    }
}
