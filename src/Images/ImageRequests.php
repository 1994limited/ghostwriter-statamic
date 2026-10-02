<?php

namespace NineteenNinetyFour\Ghostwriter\Images;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Core\Text\Utf8;

/**
 * Work the image button has asked for: a search or a picture being made
 * for one field on one form. Each request belongs to the person who made
 * it, runs in the background, and is cleared after a day along with any
 * picture it made that was not kept.
 */
class ImageRequests
{
    public const IDLE = 'idle';

    public const WORKING = 'working';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** Requests older than this are cleared. */
    private const KEEP_FOR_HOURS = 24;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $this->clearOld();

        return $this->save(['id' => (string) Str::ulid(), 'status' => self::WORKING, 'error' => null, 'created_at' => Carbon::now()->toIso8601String()] + $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (! preg_match('/^[0-9A-Za-z]{26}$/', $id) || ! File::exists($this->path($id))) {
            return null;
        }

        return (array) json_decode((string) File::get($this->path($id)), true) ?: null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function save(array $data): array
    {
        File::ensureDirectoryExists($this->directory());
        File::put($this->path($data['id']), json_encode(Utf8::scrub($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $changes): ?array
    {
        $data = $this->find($id);

        return $data ? $this->save(array_merge($data, $changes)) : null;
    }

    /**
     * Where a picture made for a request, or a source image uploaded for
     * one, is kept until it is used or cleared.
     */
    public function file(string $id, string $extension): string
    {
        File::ensureDirectoryExists($this->directory().'/files');

        return $this->directory().'/files/'.basename($id).'.'.preg_replace('/[^a-z0-9]/', '', strtolower($extension));
    }

    private function clearOld(): void
    {
        if (! File::isDirectory($this->directory())) {
            return;
        }

        $cutoff = Carbon::now()->subHours(self::KEEP_FOR_HOURS)->timestamp;

        foreach (File::files($this->directory()) as $file) {
            if ($file->getMTime() < $cutoff) {
                $data = (array) json_decode((string) File::get($file->getPathname()), true);

                foreach (array_filter([$data['file'] ?? null, $data['source'] ?? null]) as $path) {
                    File::delete($path);
                }

                File::delete($file->getPathname());
            }
        }
    }

    private function directory(): string
    {
        return dirname((string) config('ghostwriter.sessions_path')).'/images';
    }

    private function path(string $id): string
    {
        return $this->directory().'/'.basename($id).'.json';
    }
}
