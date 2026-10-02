<?php

namespace NineteenNinetyFour\Ghostwriter\Subscribers;

use Illuminate\Events\Dispatcher;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Stock\CompStore;
use NineteenNinetyFour\Ghostwriter\Stock\Ledger;
use NineteenNinetyFour\Ghostwriter\Stock\UsageScanner;
use Statamic\Events\AssetDeleted;
use Statamic\Events\AssetSaved;
use Statamic\Events\EntrySaved;
use Throwable;

/**
 * Keeps the ledger in step with the site:
 *
 * - each saved entry is scanned for the ledger's assets, and where they
 *   are used on it is synced (added, kept, or gone);
 * - an asset moved or renamed keeps its record;
 * - an asset deleted marks its record removed (a licence stays on file)
 *   and lets its private comp go.
 *
 * None of this ever stops a save: a failure is reported and left.
 */
class TrackStockImages
{
    public function __construct(private Ledger $ledger, private UsageScanner $scanner, private CompStore $comps) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EntrySaved::class, [self::class, 'entrySaved']);
        $events->listen(AssetSaved::class, [self::class, 'assetSaved']);
        $events->listen(AssetDeleted::class, [self::class, 'assetDeleted']);
    }

    public function entrySaved(EntrySaved $event): void
    {
        $entry = $event->entry;

        try {
            $found = $this->scanner->scan($entry, $entry->data()->all());

            if ($found === [] && ! $this->ledger->hasRecords()) {
                return;
            }

            $this->ledger->images()->syncUsages('entry', (string) $entry->id(), $entry->locale(), $found, (bool) $entry->published(), Ledger::person());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function assetSaved(AssetSaved $event): void
    {
        $asset = $event->asset;
        $original = $asset->getOriginal('path');

        if (! is_string($original) || $original === '' || $original === $asset->path()) {
            return;
        }

        try {
            $image = $this->ledger->images()->store()->forAsset(AssetRef::statamic($asset->container()->handle(), $original));

            if ($image !== null) {
                $this->ledger->images()->change($image->id, fn (StockImage $image) => $image->asset = Ledger::ref($asset));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function assetDeleted(AssetDeleted $event): void
    {
        try {
            $image = $this->ledger->forAsset($event->asset);

            if ($image === null || $image->is(StockImage::LICENSING)) {
                return;
            }

            $comp = $image->comp();
            $this->ledger->images()->removed($image->id, Ledger::person());
            $this->comps->forget($comp);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
