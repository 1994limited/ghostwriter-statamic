<?php

namespace NineteenNinetyFour\Ghostwriter\Storage;

use DateTimeInterface;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\StoredFile;

/**
 * Image requests are JSON files under `images/` beside the sessions, and
 * the pictures waiting beside them (one made, or one uploaded to build it
 * around) are kept in `images/files` until they are used or cleared. A
 * request records where its pictures are: `file` (with its `mime`) and
 * `source`.
 */
class FileImageRequestStore implements ImageRequestStore
{
    use JsonFiles;

    private const MIMES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    public function save(ImageRequest $request): ImageRequest
    {
        $this->writeJson($this->path($request->id), $request->toArray());

        return $request;
    }

    public function find(string $id): ?ImageRequest
    {
        if (! Format::Statamic->isSessionId($id)) {
            return null;
        }

        $data = $this->readJson($this->path($id));

        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        $request = ImageRequest::fromArray($data, Format::Statamic);
        $request->changedAt = $this->modified($this->path($id));

        return $request;
    }

    public function delete(string $id): void
    {
        if (! Format::Statamic->isSessionId($id)) {
            return;
        }

        $this->deleteWithFiles($this->path($id));
    }

    public function clearOlderThan(DateTimeInterface $cutoff): int
    {
        $gone = 0;

        foreach (glob($this->directory().'/*.json') ?: [] as $path) {
            if ((int) filemtime($path) < $cutoff->getTimestamp()) {
                $this->deleteWithFiles($path);
                $gone++;
            }
        }

        // Anything left behind by a request that went some other way.
        foreach (glob($this->directory().'/files/*') ?: [] as $path) {
            if (is_file($path) && (int) filemtime($path) < $cutoff->getTimestamp()) {
                File::delete($path);
            }
        }

        return $gone;
    }

    public function putFile(string $id, string $which, StoredFile $file): void
    {
        $extension = (string) preg_replace('/[^a-z0-9]/', '', strtolower($file->extension)) ?: 'png';
        $path = $this->filesDirectory().'/'.basename($id).($which === StoredFile::SOURCE ? '-source' : '').'.'.$extension;

        File::ensureDirectoryExists($this->filesDirectory());
        File::put($path, $file->content);

        // The request records where its picture is, as it always has.
        if ($data = $this->readJson($this->path($id))) {
            if ($which === StoredFile::SOURCE) {
                $data['source'] = $path;
            } else {
                $data['file'] = $path;
                $data['mime'] = $file->mime;
            }

            $this->writeJson($this->path($id), $data);
        }
    }

    public function file(string $id, string $which): ?StoredFile
    {
        if (! Format::Statamic->isSessionId($id)) {
            return null;
        }

        $data = $this->readJson($this->path($id)) ?? [];
        $path = $data[$which === StoredFile::SOURCE ? 'source' : 'file'] ?? null;

        if (! is_string($path) || ! is_file($path) || ! $this->isOurs($path)) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = $which === StoredFile::MADE && is_string($data['mime'] ?? null) ? $data['mime'] : (self::MIMES[$extension] ?? 'application/octet-stream');

        return new StoredFile((string) File::get($path), $mime, $extension);
    }

    private function deleteWithFiles(string $path): void
    {
        $data = $this->readJson($path) ?? [];

        foreach ([$data['file'] ?? null, $data['source'] ?? null] as $file) {
            if (is_string($file) && $file !== '' && $this->isOurs($file)) {
                File::delete($file);
            }
        }

        File::delete([$path, substr($path, 0, -strlen('.json')).'.lock']);
    }

    /**
     * A picture this store kept, not some other path a record names.
     */
    private function isOurs(string $path): bool
    {
        return dirname($path) === $this->filesDirectory();
    }

    private function directory(): string
    {
        return $this->stateDirectory().'/images';
    }

    private function filesDirectory(): string
    {
        return $this->directory().'/files';
    }

    private function path(string $id): string
    {
        return $this->directory().'/'.basename($id).'.json';
    }
}
