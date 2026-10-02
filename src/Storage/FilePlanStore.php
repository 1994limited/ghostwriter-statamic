<?php

namespace NineteenNinetyFour\Ghostwriter\Storage;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;
use Statamic\Facades\YAML;

/**
 * The content plan is one YAML file in the project (`ideas.yaml`, its
 * `ideas` list), so the plan is versioned with the site and can be edited
 * there too. The plan screen's working state is `plan.json` beside the
 * sessions, as it is not content.
 */
class FilePlanStore implements PlanStore
{
    use JsonFiles;

    public function ideas(): array
    {
        if (! is_file($this->path())) {
            return [];
        }

        $ideas = [];

        foreach ((array) (YAML::parse((string) File::get($this->path()))['ideas'] ?? []) as $item) {
            // An idea needs a title and a collection to be one.
            if (! is_array($item) || empty($item['title']) || empty($item['collection'])) {
                continue;
            }

            $idea = Idea::fromArray($item, Format::Statamic);
            $idea->id ??= Format::Statamic->newId();
            $ideas[] = $idea;
        }

        return $ideas;
    }

    public function find(int|string $id): ?Idea
    {
        foreach ($this->ideas() as $idea) {
            if ((string) $idea->id === (string) $id) {
                return $idea;
            }
        }

        return null;
    }

    public function save(Idea $idea): Idea
    {
        $idea->id ??= Format::Statamic->newId();

        $ideas = $this->ideas();
        $found = false;

        foreach ($ideas as $i => $existing) {
            if ((string) $existing->id === (string) $idea->id) {
                $ideas[$i] = $idea;
                $found = true;
            }
        }

        if (! $found) {
            $ideas[] = $idea;
        }

        $this->write($ideas);

        return $idea;
    }

    public function delete(int|string $id): void
    {
        $ideas = $this->ideas();
        $kept = array_values(array_filter($ideas, fn (Idea $idea) => (string) $idea->id !== (string) $id));

        if (count($kept) !== count($ideas)) {
            $this->write($kept);
        }
    }

    public function state(): PlanState
    {
        $data = $this->readJson($this->statePath());
        $state = $data === null ? PlanState::empty(Format::Statamic) : PlanState::fromArray($data, Format::Statamic);
        $state->changedAt = $this->modified($this->statePath());

        return $state;
    }

    public function saveState(PlanState $state): void
    {
        $this->writeJson($this->statePath(), $state->toArray());
    }

    /**
     * @param  array<int, Idea>  $ideas
     */
    private function write(array $ideas): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), YAML::dump(['ideas' => array_map(fn (Idea $idea) => $idea->toArray(), array_values($ideas))]));
    }

    private function path(): string
    {
        return (string) config('ghostwriter.plan.path', resource_path('ghostwriter/ideas.yaml'));
    }

    private function statePath(): string
    {
        return $this->stateDirectory().'/plan.json';
    }
}
