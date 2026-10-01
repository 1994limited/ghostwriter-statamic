<?php

namespace NineteenNinetyFour\Ghostwriter\Sessions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Ai\Text;

/**
 * Sessions are stored one JSON file each, so the addon needs no database and
 * works on a flat-file site out of the box.
 */
class SessionRepository
{
    /**
     * @return Collection<int, Session>
     */
    public function all(): Collection
    {
        if (! File::isDirectory($this->directory())) {
            return collect();
        }

        return collect(File::files($this->directory()))
            ->filter(fn ($file) => $file->getExtension() === 'json')
            ->map(fn ($file) => $this->read($file->getPathname()))
            ->filter()
            ->sortByDesc(fn (Session $session) => $session->updatedAt)
            ->values();
    }

    public function find(string $id): ?Session
    {
        // IDs are ULIDs; anything else is not ours to look up.
        if (! preg_match('/^[0-9A-Za-z]{26}$/', $id)) {
            return null;
        }

        return $this->read($this->path($id));
    }

    public function save(Session $session): Session
    {
        $session->updatedAt = Carbon::now()->toIso8601String();

        File::ensureDirectoryExists($this->directory());
        File::put($this->path($session->id), json_encode(Text::scrub($session->toArray()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $session;
    }

    public function delete(Session $session): void
    {
        File::delete($this->path($session->id));
    }

    private function read(string $path): ?Session
    {
        if (! File::exists($path)) {
            return null;
        }

        $data = json_decode((string) File::get($path), true);

        return is_array($data) && isset($data['id'], $data['type']) ? Session::fromArray($data) : null;
    }

    private function directory(): string
    {
        return (string) config('ghostwriter.sessions_path');
    }

    private function path(string $id): string
    {
        return $this->directory().'/'.$id.'.json';
    }
}
