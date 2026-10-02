<?php

namespace NineteenNinetyFour\Ghostwriter\Stock;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\Person;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;
use Throwable;

/**
 * The stock image ledger as the Statamic addon writes to it: core's
 * StockImages, with Statamic's assets (`container::path`), people and
 * entries. Each asset the ledger knows carries its record's ID in its own
 * data (`ghostwriter_stock`), so a copy of the file can be traced.
 */
class Ledger
{
    /** The asset data key that holds the ledger record's ID. */
    public const MARKER = 'ghostwriter_stock';

    public function __construct(private StockImages $images) {}

    public function images(): StockImages
    {
        return $this->images;
    }

    /**
     * A free library's photo, saved as the final file: licensed at once.
     * Recording never stops the photo being used: a failure is reported
     * and the photo stays.
     */
    public function recordFree(Photo $photo, Asset $asset, ?Usage $usage = null, bool $noModelInput = false): ?StockImage
    {
        try {
            $image = $this->images->recordFree($photo, self::ref($asset), self::person(), $usage, $noModelInput);
            $this->mark($asset, $image);

            return $image;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Whether the ledger holds any record at all: most sites' entry saves
     * have nothing to sync.
     */
    public function hasRecords(): bool
    {
        return $this->images->store()->query(new StockImageQuery(perPage: 1))->total > 0;
    }

    /**
     * The live record for an asset, if the ledger has one.
     */
    public function forAsset(Asset $asset): ?StockImage
    {
        return $this->images->store()->forAsset(self::ref($asset));
    }

    /**
     * Writes the record's ID into the asset's data.
     */
    public function mark(Asset $asset, StockImage $image): void
    {
        if ($asset->get(self::MARKER) !== $image->id) {
            $asset->set(self::MARKER, $image->id)->saveQuietly();
        }
    }

    public static function ref(Asset $asset): AssetRef
    {
        return AssetRef::statamic($asset->container()->handle(), $asset->path());
    }

    /**
     * Where an image is used on an entry: its field, on the entry's site.
     */
    public static function usage(Entry $entry, string $field, ?string $label = null): Usage
    {
        return new Usage('entry', (string) $entry->id(), $field, $entry->locale(), $label, live: (bool) $entry->published());
    }

    /**
     * Who is doing this: the signed-in user, by ID with their name as it is now.
     */
    public static function person(?UserContract $user = null): ?Person
    {
        $user ??= User::current();

        return $user ? new Person((string) $user->id(), (string) ($user->name() ?: $user->email())) : null;
    }
}
