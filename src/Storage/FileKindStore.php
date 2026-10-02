<?php

namespace NineteenNinetyFour\Ghostwriter\Storage;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\Analysis;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use Statamic\Facades\YAML;

/**
 * Kinds of content are YAML files in the project (`types/<handle>.yaml`),
 * one per kind, so they are versioned with the site and can be edited by
 * hand. The kinds suggested for each collection (`kinds.json`) and whether
 * each collection is being studied (`types.json`) are working state, kept
 * beside the sessions.
 */
class FileKindStore implements KindStore
{
    use JsonFiles;

    public function all(): array
    {
        $types = [];

        foreach (glob($this->directory().'/*.yaml') ?: [] as $path) {
            $handle = pathinfo($path, PATHINFO_FILENAME);
            $types[] = ContentType::fromArray((array) YAML::parse((string) File::get($path)), Format::Statamic, $handle);
        }

        usort($types, fn (ContentType $a, ContentType $b) => $a->title <=> $b->title);

        return $types;
    }

    public function find(string $handle): ?ContentType
    {
        // Looked up among the files there are, so a handle is never a path.
        foreach ($this->all() as $type) {
            if ($type->handle === $handle) {
                return $type;
            }
        }

        return null;
    }

    public function save(ContentType $type): ContentType
    {
        File::ensureDirectoryExists($this->directory());
        File::put($this->directory().'/'.basename($type->handle).'.yaml', YAML::dump($type->toArray()));

        return $type;
    }

    public function delete(string $handle): void
    {
        if ($this->find($handle) !== null) {
            File::delete($this->directory().'/'.basename($handle).'.yaml');
        }
    }

    public function suggestions(string $group): KindSuggestions
    {
        $stored = $this->readJson($this->suggestionsPath())[$group] ?? null;
        $suggestions = is_array($stored) ? KindSuggestions::fromArray($stored, Format::Statamic) : KindSuggestions::empty(Format::Statamic);
        $suggestions->changedAt = $this->modified($this->suggestionsPath());

        return $suggestions;
    }

    public function saveSuggestions(string $group, KindSuggestions $suggestions): void
    {
        $all = $this->readJson($this->suggestionsPath()) ?? [];
        $all[$group] = $suggestions->toArray();

        $this->writeJson($this->suggestionsPath(), $all);
    }

    public function analysis(string $group): Analysis
    {
        $stored = $this->readJson($this->analysisPath())[$group] ?? null;
        $analysis = is_array($stored) ? Analysis::fromArray($stored) : new Analysis;
        $analysis->changedAt = $this->modified($this->analysisPath());

        return $analysis;
    }

    public function saveAnalysis(string $group, Analysis $analysis): void
    {
        $all = $this->readJson($this->analysisPath()) ?? [];
        $all[$group] = $analysis->toArray();

        // Written as it always has been: slashes escaped.
        $this->writeJson($this->analysisPath(), $all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function directory(): string
    {
        return (string) config('ghostwriter.types_path');
    }

    private function suggestionsPath(): string
    {
        return $this->stateDirectory().'/kinds.json';
    }

    private function analysisPath(): string
    {
        return $this->stateDirectory().'/types.json';
    }
}
