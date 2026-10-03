<?php

namespace NineteenNinetyFour\Ghostwriter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Stock\CompStore;
use NineteenNinetyFour\Ghostwriter\Stock\Ledger;
use NineteenNinetyFour\Ghostwriter\Stock\Licensing;
use Statamic\Facades\AssetContainer;
use Throwable;

/**
 * Keeps stock previews to the libraries' terms (design §7.4), on the
 * scheduler:
 *
 * - a comp is deleted when the library's comp period ends (Getty and
 *   iStock 30 days), whether or not its preview is still in use; the
 *   stand-in stays, so drafts don't break, and the badge reads "Preview
 *   expired";
 * - a stand-in no page has used for `stock.unused_preview_days` (30) is
 *   deleted and its record marked removed; one still in use is never
 *   deleted;
 * - a licence whose outcome wasn't known is settled with the library
 *   after ten minutes (reconcile()), never bought again.
 */
class StockCleanup extends Command
{
    protected $signature = 'ghostwriter:stock-cleanup';

    protected $description = 'Delete expired stock comps and unused previews, and settle licences whose outcome was not known';

    public function handle(Ledger $ledger, CompStore $comps, Licensing $licensing): int
    {
        $images = $ledger->images();
        $now = Carbon::now()->toImmutable();
        [$expired, $removed, $reconciled] = [0, 0, 0];

        foreach ($images->all(new StockImageQuery([StockImage::PREVIEW, StockImage::LICENSING, StockImage::FAILED], perPage: 500)) as $image) {
            if ($image->comp() !== null && $image->compKeepUntil() !== null && $image->compKeepUntil() <= $now) {
                $comps->forget($image->comp());
                $images->compExpired($image->id);
                $expired++;
            }
        }

        $cutoff = $now->modify('-'.max(1, (int) config('ghostwriter.stock.unused_preview_days', 30)).' days');

        foreach ($images->store()->previewsBefore($cutoff) as $image) {
            if ($image->usages() !== []) {
                continue;
            }

            $asset = AssetContainer::find($image->asset->volume)?->asset($image->asset->path);
            $comps->forget($image->comp());

            // Deleting the stand-in marks the record removed (TrackStockImages).
            $asset ? $asset->delete() : $images->removed($image->id);
            $removed++;
        }

        foreach ($images->dueForReconcile() as $image) {
            try {
                if (! $licensing->reconcile($image)->is(StockImage::LICENSING)) {
                    $reconciled++;
                }
            } catch (Throwable $exception) {
                $this->warn("Couldn't check {$image->library} {$image->externalId}: {$exception->getMessage()}");
            }
        }

        $this->info("Stock images: {$expired} expired ".($expired === 1 ? 'comp' : 'comps')." deleted, {$removed} unused ".($removed === 1 ? 'preview' : 'previews')." removed, {$reconciled} ".($reconciled === 1 ? 'licence' : 'licences').' settled.');

        return self::SUCCESS;
    }
}
