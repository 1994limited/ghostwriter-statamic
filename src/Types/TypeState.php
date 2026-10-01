<?php

namespace NineteenNinetyFour\Ghostwriter\Types;

use Illuminate\Support\Facades\File;

/**
 * Whether each collection is being analysed right now, and the last error.
 */
class TypeState
{
    public const IDLE = 'idle';

    public const WORKING = 'working';

    public const FAILED = 'failed';

    /**
     * @return array{status: string, error: ?string}
     */
    public function get(string $collection): array
    {
        return array_merge(['status' => self::IDLE, 'error' => null], $this->all()[$collection] ?? []);
    }

    public function set(string $collection, string $status, ?string $error = null): void
    {
        $all = $this->all();
        $all[$collection] = ['status' => $status, 'error' => $error];

        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, array{status: string, error: ?string}>
     */
    private function all(): array
    {
        return File::exists($this->path()) ? (array) json_decode((string) File::get($this->path()), true) : [];
    }

    private function path(): string
    {
        return dirname((string) config('ghostwriter.sessions_path')).'/types.json';
    }
}
