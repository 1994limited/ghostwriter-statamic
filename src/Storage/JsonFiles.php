<?php

namespace NineteenNinetyFour\Ghostwriter\Storage;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Text\Utf8;

/**
 * Ghostwriter's working state is small JSON files beside the sessions, so
 * the addon needs no database. Each file keeps the JSON flags it has always
 * been written with, so a record read and saved unchanged is unchanged on
 * disk.
 *
 * @internal
 */
trait JsonFiles
{
    /** How the session, plan, guide, kind and image request files are written. */
    private const JSON = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @return array<mixed>|null Null when there is no file, or it isn't a JSON object or list.
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) File::get($path), true);

        return is_array($data) ? $data : null;
    }

    private function writeJson(string $path, mixed $data, int $flags = self::JSON): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode(Utf8::scrub($data), $flags));
    }

    /**
     * Where Ghostwriter keeps its working state: beside the sessions.
     */
    private function stateDirectory(): string
    {
        return dirname((string) config('ghostwriter.sessions_path'));
    }

    /**
     * When a file was last written, for telling stopped work (CRA-2).
     */
    private function modified(string $path): ?int
    {
        clearstatcache(true, $path);

        return is_file($path) ? (int) filemtime($path) : null;
    }
}
