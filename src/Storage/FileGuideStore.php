<?php

namespace NineteenNinetyFour\Ghostwriter\Storage;

use DateTimeImmutable;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;

/**
 * The voice guide and the image style guide are markdown files in the
 * project (`voice.md`, `imagery.md`), edited by hand or through their
 * screens. Each screen's working state (`voice.json`, `imagery.json`) is
 * kept beside the sessions, as it is not content.
 */
class FileGuideStore implements GuideStore
{
    use JsonFiles;

    public function guide(string $kind): Guide
    {
        $path = $this->path($kind);
        $body = is_file($path) ? (string) File::get($path) : '';

        if (trim($body) === '') {
            return new Guide($kind);
        }

        return new Guide($kind, $body, (new DateTimeImmutable)->setTimestamp((int) $this->modified($path)));
    }

    public function saveGuide(Guide $guide): Guide
    {
        $path = $this->path($guide->kind);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, Guide::normalise($guide->body));

        return $this->guide($guide->kind);
    }

    public function state(string $kind): GuideState
    {
        $path = $this->statePath($kind);
        $data = $this->readJson($path);
        $state = $data === null ? GuideState::empty(Format::Statamic) : GuideState::fromArray($data, Format::Statamic);
        $state->changedAt = $this->modified($path);

        return $state;
    }

    public function saveState(string $kind, GuideState $state): void
    {
        $this->writeJson($this->statePath($kind), $state->toArray());
    }

    private function path(string $kind): string
    {
        return match ($kind) {
            Guide::VOICE => (string) config('ghostwriter.voice.path'),
            Guide::IMAGERY => (string) config('ghostwriter.images.guide_path', resource_path('ghostwriter/imagery.md')),
            default => throw new InvalidArgumentException("There is no \"{$kind}\" guide."),
        };
    }

    private function statePath(string $kind): string
    {
        $this->path($kind);

        return $this->stateDirectory().'/'.$kind.'.json';
    }
}
