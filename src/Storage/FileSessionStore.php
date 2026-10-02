<?php

namespace NineteenNinetyFour\Ghostwriter\Storage;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;

/**
 * Sessions are stored one JSON file each in `ghostwriter.sessions_path`, so
 * the addon needs no database and works on a flat-file site out of the box.
 * Each session's lock file sits beside it (FileLock).
 */
class FileSessionStore implements SessionStore
{
    use JsonFiles;

    public function all(): array
    {
        $sessions = [];

        foreach (glob($this->directory().'/*.json') ?: [] as $path) {
            if ($session = $this->read($path)) {
                $sessions[] = $session;
            }
        }

        // The latest change first; files changed in the same second stay in
        // name order.
        usort($sessions, fn (Session $a, Session $b) => (Format::parse($b->updatedAt)?->getTimestamp() ?? 0) <=> (Format::parse($a->updatedAt)?->getTimestamp() ?? 0));

        return $sessions;
    }

    public function startedBy(int|string $userId): array
    {
        return array_values(array_filter($this->all(), fn (Session $session) => $session->startedBy !== null && (string) $session->startedBy === (string) $userId));
    }

    public function find(string $id): ?Session
    {
        // IDs are ULIDs; anything else is not ours to look up.
        if (! Format::Statamic->isSessionId($id)) {
            return null;
        }

        return $this->read($this->path($id));
    }

    public function save(Session $session): Session
    {
        $session->updatedAt = Format::Statamic->stamp(Carbon::now());

        $this->writeJson($this->path($session->id), $session->toArray());

        return $session;
    }

    public function delete(string $id): void
    {
        if (! Format::Statamic->isSessionId($id)) {
            return;
        }

        File::delete([$this->path($id), $this->directory().'/'.$id.'.lock']);
    }

    private function read(string $path): ?Session
    {
        $data = $this->readJson($path);

        return is_array($data) && isset($data['id'], $data['type']) ? Session::fromArray($data, Format::Statamic) : null;
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
