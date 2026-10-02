<?php

namespace NineteenNinetyFour\Ghostwriter\Sessions;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Text\Utf8;
use Statamic\Contracts\Auth\User;

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

    /**
     * Whether conversations are shared with everyone who may use
     * Ghostwriter (`shared_conversations`, on by default), rather than kept
     * to the person who started each.
     */
    public function shared(): bool
    {
        return (bool) config('ghostwriter.shared_conversations', true);
    }

    /**
     * The sessions a person may see, newest first: everyone's when
     * conversations are shared; otherwise their own, or all for a super user.
     *
     * @return Collection<int, Session>
     */
    public function visibleTo(?User $user): Collection
    {
        return $this->all()->filter(fn (Session $session) => $this->canSee($session, $user))->values();
    }

    /**
     * Whether a person may open, carry on with, use or remove a session.
     * Everyone who may use Ghostwriter (the routes see to that) when
     * conversations are shared; otherwise only its own person.
     */
    public function canSee(Session $session, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->shared() || $session->belongsTo($user);
    }

    public function find(string $id): ?Session
    {
        // IDs are ULIDs; anything else is not ours to look up.
        if (! preg_match('/^[0-9A-Za-z]{26}$/', $id)) {
            return null;
        }

        return $this->read($this->path($id));
    }

    /**
     * Do something to a session while holding its lock, so two requests
     * can't both find it idle and start a run, or save over each other.
     * Read the session again inside: what was read before may be stale.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function exclusively(string $id, Closure $work): mixed
    {
        // Not a session ID: nothing to lock, and $work will find nothing.
        if (! preg_match('/^[0-9A-Za-z]{26}$/', $id)) {
            return $work();
        }

        File::ensureDirectoryExists($this->directory());

        $handle = @fopen($this->directory().'/'.$id.'.lock', 'c');

        if ($handle === false) {
            return $work();
        }

        try {
            flock($handle, LOCK_EX);

            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function save(Session $session): Session
    {
        $session->updatedAt = Carbon::now()->toIso8601String();

        File::ensureDirectoryExists($this->directory());
        File::put($this->path($session->id), json_encode(Utf8::scrub($session->toArray()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $session;
    }

    public function delete(Session $session): void
    {
        File::delete([$this->path($session->id), $this->directory().'/'.$session->id.'.lock']);
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
