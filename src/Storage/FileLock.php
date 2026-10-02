<?php

namespace NineteenNinetyFour\Ghostwriter\Storage;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\LockTimeout;

/**
 * Core's Lock as an exclusive `flock`, which every PHP process on the
 * server sees whatever cache the site uses, and which the system lets go
 * if a process dies holding it. A session's or an image request's lock
 * is `<id>.lock` beside it; any other key's is under `locks/` beside the
 * sessions.
 *
 * A key this process already holds runs its work at once rather than
 * waiting on itself: flock locks belong to the open file, so a second
 * open in the same process would deadlock.
 */
class FileLock implements Lock
{
    /** How often a held lock is tried again, in microseconds. */
    private const RETRY = 50_000;

    /** @var array<string, int> Keys this process holds, and how deeply. */
    private static array $held = [];

    public function run(string $key, callable $work, int $waitSeconds = 15): mixed
    {
        $path = $this->path($key);

        // Not a session ID: nothing to lock, and the work will find nothing.
        if ($path === null || isset(self::$held[$path])) {
            return $this->hold($path, $work);
        }

        File::ensureDirectoryExists(dirname($path));

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            return $work();
        }

        try {
            $deadline = microtime(true) + max(0, $waitSeconds);

            while (! flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new LockTimeout;
                }

                usleep(self::RETRY);
            }

            try {
                return $this->hold($path, $work);
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function hold(?string $path, callable $work): mixed
    {
        if ($path === null) {
            return $work();
        }

        self::$held[$path] = (self::$held[$path] ?? 0) + 1;

        try {
            return $work();
        } finally {
            if (--self::$held[$path] === 0) {
                unset(self::$held[$path]);
            }
        }
    }

    private function path(string $key): ?string
    {
        $sessions = (string) config('ghostwriter.sessions_path');

        // A session's or an image request's lock sits beside it, and goes with it.
        foreach (['session:' => $sessions, 'image:' => dirname($sessions).'/images'] as $prefix => $directory) {
            if (str_starts_with($key, $prefix)) {
                $id = substr($key, strlen($prefix));

                return Format::Statamic->isSessionId($id) && ! str_contains($id, "\n") ? $directory.'/'.$id.'.lock' : null;
            }
        }

        $name = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $key), '-');

        if ($name === '' || strlen($name) > 100) {
            $name = sha1($key);
        }

        return dirname($sessions).'/locks/'.$name.'.lock';
    }
}
