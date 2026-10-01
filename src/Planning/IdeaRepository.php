<?php

namespace NineteenNinetyFour\Ghostwriter\Planning;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Facades\YAML;

/**
 * The content plan: ideas for things the site does not have yet. Some are
 * suggested by Ghostwriter from what is missing, some added by hand. It is
 * one YAML file in the project, so the plan is versioned with the site and
 * can be edited there too.
 */
class IdeaRepository
{
    public const OPEN = 'open';

    public const DRAFTED = 'drafted';

    public const DISMISSED = 'dismissed';

    /**
     * @return Collection<string, array{id: string, title: string, collection: string, type: ?string, why: string, notes: string, status: string, source: string, session: ?string, created_at: string}>
     */
    public function all(): Collection
    {
        if (! File::exists($this->path())) {
            return collect();
        }

        return collect((array) (YAML::parse((string) File::get($this->path()))['ideas'] ?? []))
            ->filter(fn ($idea) => is_array($idea) && ! empty($idea['title']) && ! empty($idea['collection']))
            ->map(fn (array $idea) => $idea + ['id' => (string) Str::ulid(), 'type' => null, 'why' => '', 'notes' => '', 'status' => self::OPEN, 'source' => 'added', 'session' => null, 'created_at' => Carbon::now()->toDateString()])
            ->keyBy('id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        return $this->all()->get($id);
    }

    /**
     * @param  array<string, mixed>  $idea
     * @return array<string, mixed>
     */
    public function add(array $idea, string $source = 'added'): array
    {
        $idea = [
            'id' => (string) Str::ulid(),
            'title' => trim((string) $idea['title']),
            'collection' => (string) $idea['collection'],
            'type' => ($idea['type'] ?? null) ?: null,
            'why' => trim((string) ($idea['why'] ?? '')),
            'notes' => trim((string) ($idea['notes'] ?? '')),
            'status' => self::OPEN,
            'source' => $source,
            'session' => null,
            'created_at' => Carbon::now()->toDateString(),
        ];

        $this->write($this->all()->put($idea['id'], $idea));

        return $idea;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $changes): ?array
    {
        $ideas = $this->all();

        if (! $ideas->has($id)) {
            return null;
        }

        $ideas->put($id, array_merge($ideas->get($id), array_intersect_key($changes, array_flip(['title', 'collection', 'type', 'why', 'notes', 'status', 'session']))));

        $this->write($ideas);

        return $ideas->get($id);
    }

    /**
     * Remove every idea in one state, such as all the open ones.
     *
     * @return int How many went.
     */
    public function clear(string $status): int
    {
        $ideas = $this->all();
        $keep = $ideas->reject(fn (array $idea) => $idea['status'] === $status);

        $this->write($keep);

        return $ideas->count() - $keep->count();
    }

    public function delete(string $id): void
    {
        $this->write($this->all()->forget($id));
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $ideas
     */
    private function write(Collection $ideas): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), YAML::dump(['ideas' => $ideas->values()->all()]));
    }

    private function path(): string
    {
        return (string) config('ghostwriter.plan.path', resource_path('ghostwriter/ideas.yaml'));
    }
}
