<?php

namespace NineteenNinetyFour\Ghostwriter\Storage;

use DateTimeInterface;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImagePage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;
use Statamic\Facades\YAML;
use Throwable;

/**
 * The stock image ledger: one YAML file per record in
 * `content/ghostwriter/stock/<id>.yaml` (`ghostwriter.stock_path`). It is
 * in `content/`, not `storage/`, so licence records are committed with the
 * site and travel between environments as entries do, and one file per
 * record keeps git merges clean.
 *
 * There is no index: at the sizes expected (hundreds) the directory is
 * read when a list is wanted, and each file is parsed once while it is
 * unchanged. A single record is always read from disk, so a change made
 * under the record's lock sees the latest.
 *
 * There is deliberately no way to delete a record: licence records are
 * permanent. Saving goes through StockImage::over(), so history only
 * grows and a licensed record never goes back to being a preview.
 */
class FileStockImageStore implements StockImageStore
{
    /** A record's ID, as Format::Statamic makes them (a ULID). */
    private const ID = '/^[0-9A-Za-z]{26}$/';

    /** @var array<string, array{0: string, 1: array<string, mixed>}> Parsed files by path: their signature and data. */
    private array $parsed = [];

    public function save(StockImage $image): StockImage
    {
        if (! preg_match(self::ID, $image->id)) {
            throw new \InvalidArgumentException('A stock image record needs an ID of its own.');
        }

        $image = $image->over($this->find($image->id));
        $path = $this->path($image->id);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, YAML::dump($image->toArray(Format::Statamic)));
        unset($this->parsed[$path]);

        return $image;
    }

    public function find(string $id): ?StockImage
    {
        if (! preg_match(self::ID, $id)) {
            return null;
        }

        return $this->read($this->path($id), fresh: true);
    }

    public function forAsset(AssetRef $asset): ?StockImage
    {
        foreach ($this->all() as $image) {
            if (! $image->is(StockImage::REMOVED) && $image->asset->is($asset)) {
                return $image;
            }
        }

        return null;
    }

    public function forExternal(string $library, string $externalId): array
    {
        return array_values(array_filter($this->all(), fn (StockImage $image) => $image->library === $library && $image->externalId === $externalId));
    }

    public function query(StockImageQuery $query): StockImagePage
    {
        return $query->apply($this->all());
    }

    public function previewsBefore(DateTimeInterface $cutoff): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (StockImage $image) => $image->is(StockImage::PREVIEW) && $image->stateChangedAt()->getTimestamp() < $cutoff->getTimestamp(),
        ));
    }

    /**
     * Every record, newest first.
     *
     * @return array<int, StockImage>
     */
    private function all(): array
    {
        $images = [];

        foreach (glob($this->directory().'/*.yaml') ?: [] as $path) {
            if (preg_match(self::ID, basename($path, '.yaml')) && ($image = $this->read($path)) !== null) {
                $images[] = $image;
            }
        }

        return StockImageQuery::newestFirst($images);
    }

    /**
     * One record's file, parsed; a file that can't be read as a record is
     * skipped rather than stopping the ledger.
     */
    private function read(string $path, bool $fresh = false): ?StockImage
    {
        clearstatcache(true, $path);

        if (! is_file($path)) {
            return null;
        }

        $signature = filemtime($path).':'.filesize($path);

        if ($fresh || ($this->parsed[$path][0] ?? null) !== $signature) {
            try {
                $data = YAML::parse((string) File::get($path));
            } catch (Throwable) {
                return null;
            }

            if (! is_array($data) || ($data['id'] ?? null) !== basename($path, '.yaml')) {
                return null;
            }

            $this->parsed[$path] = [$signature, $data];
        }

        // Made afresh each time, so a change one caller makes isn't seen by the next.
        return StockImage::fromArray($this->parsed[$path][1], Format::Statamic);
    }

    private function directory(): string
    {
        return rtrim((string) config('ghostwriter.stock_path', base_path('content/ghostwriter/stock')), '/');
    }

    private function path(string $id): string
    {
        return $this->directory().'/'.$id.'.yaml';
    }
}
