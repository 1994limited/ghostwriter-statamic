<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Support\Facades\File;

/**
 * Work sent to the queue and not yet picked up. A job is marked when it is
 * sent and unmarked the moment a worker starts it, so work still marked a
 * while later most likely has no worker listening on its queue, and the
 * screen can say so rather than spin on.
 *
 * Kept as small files beside Ghostwriter's other state, not in the cache,
 * as the worker and the web request must see the same mark whatever cache
 * store the site uses.
 */
class Waiting
{
    /** Seconds before waiting work is mentioned. */
    public const AFTER = 30;

    public function queued(string $subject): void
    {
        File::ensureDirectoryExists($this->directory());
        File::put($this->file($subject), (string) now()->getTimestamp());
    }

    public function started(string $subject): void
    {
        File::delete($this->file($subject));
    }

    /**
     * Seconds the work has waited for a worker, or null once one has it.
     */
    public function waited(string $subject): ?int
    {
        $file = $this->file($subject);

        if (! is_file($file)) {
            return null;
        }

        $at = trim((string) @file_get_contents($file));

        return ctype_digit($at) ? max(0, now()->getTimestamp() - (int) $at) : null;
    }

    /**
     * A word when the work has waited too long for a worker, naming the
     * command to start one. Null while there is nothing to say.
     */
    public function notice(string $subject): ?string
    {
        // The sync queue runs work itself, after the response: nothing to wait for.
        if (config('queue.default') === 'sync') {
            return null;
        }

        $waited = $this->waited($subject);

        if ($waited === null || $waited < self::AFTER) {
            return null;
        }

        return "Still waiting for a queue worker to pick this up. Is `{$this->command()}` running?";
    }

    /**
     * The command that starts a worker for the queue Ghostwriter's work goes to.
     */
    public function command(): string
    {
        $connection = (string) config('queue.default');
        $queue = (string) (config("queue.connections.{$connection}.queue") ?: 'default');

        return 'php artisan queue:work'.($queue !== 'default' ? " --queue={$queue}" : '');
    }

    private function directory(): string
    {
        return (string) config('ghostwriter.queued_path', storage_path('ghostwriter/queued'));
    }

    private function file(string $subject): string
    {
        return $this->directory().'/'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $subject).'.mark';
    }
}
