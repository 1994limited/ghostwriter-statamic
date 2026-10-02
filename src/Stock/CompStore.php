<?php

namespace NineteenNinetyFour\Ghostwriter\Stock;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;

/**
 * Where paid libraries' comps (their watermarked previews) are kept:
 * `storage/ghostwriter/stock/`, beside the image requests, and never in an
 * asset container, so a comp is never at a public address. A ledger record
 * names its comp by the file's name here; only the comp route serves it,
 * to signed-in Control Panel users.
 *
 * A library whose terms allow no storage at all (Shutterstock) has its
 * preview's own address on the record instead, and nothing here.
 */
class CompStore
{
    private const NAME = '/^[0-9A-Za-z]{26}\.(png|jpg|webp)$/';

    private const MIMES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    /**
     * Keeps a comp's bytes, and gives the name the record holds.
     */
    public function put(PhotoFile $file): string
    {
        $extension = match (strtolower($file->extension)) {
            'jpeg', 'jpg' => 'jpg',
            'webp' => 'webp',
            default => 'png',
        };
        $name = Format::Statamic->newId().'.'.$extension;

        File::ensureDirectoryExists($this->directory());
        File::put($this->directory().'/'.$name, $file->content);

        return $name;
    }

    /**
     * Whether the record's comp is a file kept here (not a provider's address).
     */
    public function isFile(?string $comp): bool
    {
        return is_string($comp) && preg_match(self::NAME, $comp) === 1;
    }

    /**
     * @return array{content: string, mime: string}|null
     */
    public function get(?string $comp): ?array
    {
        if (! $this->isFile($comp) || ! is_file($path = $this->directory().'/'.$comp)) {
            return null;
        }

        return ['content' => (string) File::get($path), 'mime' => self::MIMES[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream'];
    }

    /**
     * Lets a comp's bytes go: licensed, expired, or its stand-in removed.
     */
    public function forget(?string $comp): void
    {
        if ($this->isFile($comp)) {
            File::delete($this->directory().'/'.$comp);
        }
    }

    public function directory(): string
    {
        return dirname((string) config('ghostwriter.sessions_path')).'/stock';
    }
}
