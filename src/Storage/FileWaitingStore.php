<?php

namespace NineteenNinetyFour\Ghostwriter\Storage;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\WaitingStore;

/**
 * Queue-waiting marks as small files (`queued/<subject>.mark`, holding the
 * time it was queued), not in the cache, as the worker and the web request
 * must see the same mark whatever cache store the site uses.
 */
class FileWaitingStore implements WaitingStore
{
    public function mark(string $subject, int $at): void
    {
        File::ensureDirectoryExists($this->directory());
        File::put($this->file($subject), (string) $at);
    }

    public function unmark(string $subject): void
    {
        File::delete($this->file($subject));
    }

    public function markedAt(string $subject): ?int
    {
        $file = $this->file($subject);

        if (! is_file($file)) {
            return null;
        }

        $at = trim((string) @file_get_contents($file));

        return ctype_digit($at) ? (int) $at : null;
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
