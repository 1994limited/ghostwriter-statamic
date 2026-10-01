<?php

namespace NineteenNinetyFour\Ghostwriter\Voice;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * The site's tone of voice guide: one markdown file in the project, read into
 * every writing prompt and edited either by hand or through the refine chat.
 */
class VoiceGuide
{
    public function path(): string
    {
        return (string) config('ghostwriter.voice.path');
    }

    public function exists(): bool
    {
        return File::exists($this->path()) && trim((string) File::get($this->path())) !== '';
    }

    public function get(): string
    {
        return $this->exists() ? (string) File::get($this->path()) : '';
    }

    public function save(string $markdown): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), rtrim($markdown)."\n");
    }

    public function updatedAt(): ?Carbon
    {
        return $this->exists() ? Carbon::createFromTimestamp(File::lastModified($this->path())) : null;
    }
}
