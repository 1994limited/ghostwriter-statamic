<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use NineteenNinetyFour\Ghostwriter\Stock\CompStore;
use NineteenNinetyFour\Ghostwriter\Stock\Licensing;
use NineteenNinetyFour\Ghostwriter\Stock\StockPresenter;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Stock images screen (design §7.3): every stock image Ghostwriter put
 * into the site, in tabs (Previews, Licensed, Failed, All), with where
 * each is used, its state, cost, who licensed it and when, its credit line
 * and restrictions; License, Reconcile, Remove preview and the licence
 * record to download; and a CSV of the lot for finance and audits.
 */
class StockLedgerController
{
    public const TABS = [
        'previews' => [StockImage::PREVIEW, StockImage::LICENSING],
        'licensed' => [StockImage::LICENSED],
        'failed' => [StockImage::FAILED],
        'all' => [],
    ];

    public function __construct(private StockImages $images, private StockPresenter $presenter) {}

    public function show(Request $request): InertiaResponse
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'previews';
        $page = max(1, (int) $request->query('page', 1));
        $records = $this->images->all(new StockImageQuery(self::TABS[$tab]));

        // Requested licences first on the Previews tab, then newest first.
        if ($tab === 'previews') {
            usort($records, fn (StockImage $a, StockImage $b) => (int) isset($b->toArray()['licence_requested']) <=> (int) isset($a->toArray()['licence_requested']));
        }

        $perPage = 25;

        return Inertia::render('ghostwriter::Stock', [
            'tab' => $tab,
            'tabs' => collect(self::TABS)->map(fn (array $states, string $key) => [
                'key' => $key,
                'count' => count($this->images->all(new StockImageQuery($states))),
                'url' => cp_route('ghostwriter.stock.index', ['tab' => $key]),
            ])->values(),
            'records' => array_map(fn (StockImage $image) => $this->presenter->summary($image), array_slice($records, ($page - 1) * $perPage, $perPage)),
            'page' => $page,
            'pages' => max(1, (int) ceil(count($records) / $perPage)),
            'csv_url' => cp_route('ghostwriter.stock.csv', ['tab' => $tab]),
            'can_license' => StockPresenter::canLicense(),
        ]);
    }

    /**
     * The ledger as CSV: date, library, ID, title, licence/order ID, cost,
     * licence type, licensed by, credit line, where used.
     */
    public function csv(Request $request): StreamedResponse
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'all';
        $records = $this->images->all(new StockImageQuery(self::TABS[$tab]));

        return response()->streamDownload(function () use ($records) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Library', 'ID', 'Title', 'State', 'Order ID', 'Cost', 'Licence type', 'Licensed by', 'Licensed at', 'Credit line', 'Restrictions', 'Where used'], escape: '');

            foreach ($records as $image) {
                $licence = $image->licence();
                fputcsv($out, [
                    $image->insertedAt->format('Y-m-d'),
                    $image->library,
                    $image->externalId,
                    $image->title,
                    $image->state(),
                    $licence?->orderId,
                    $licence?->cost?->label(),
                    $image->licenceType,
                    $licence?->licensedBy,
                    $licence?->licensedAt->format('Y-m-d H:i'),
                    $image->creditLine,
                    $image->restrictions,
                    implode('; ', array_map(fn (Usage $usage) => trim((Entry::find((string) $usage->ownerId)?->get('title') ?? $usage->ownerType.' '.$usage->ownerId).' ('.($usage->label ?? $usage->field).')'), $image->usages())),
                ], escape: '');
            }

            fclose($out);
        }, 'stock-images-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * One record, whole, for the files: its licence and history as kept.
     */
    public function record(string $id): JsonResponse
    {
        $image = $this->images->find($id) ?? abort(404);

        return response()->json($image->toArray(), 200, ['Content-Disposition' => 'attachment; filename="stock-licence-'.$image->library.'-'.$image->externalId.'.json"']);
    }

    /**
     * Settles a licence whose outcome wasn't known, with the library.
     */
    public function reconcile(string $id, Licensing $licensing): JsonResponse
    {
        abort_unless(StockPresenter::canLicense(), 403);
        $image = $this->images->find($id) ?? abort(404);

        try {
            $image = $licensing->reconcile($image);
        } catch (PhotoUnavailable $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json([
            'message' => match ($image->state()) {
                StockImage::LICENSED => __('The library has the licence: it is recorded. Use Download again and replace to put the file in.'),
                StockImage::FAILED => __('The library has no licence for it: nothing was bought. It can be licensed again.'),
                default => __('The library hasn\'t said yet. Ghostwriter will ask again.'),
            },
            'stock' => $this->presenter->summary($image),
        ]);
    }

    /**
     * Remove preview: the stand-in and its comp go, and the record is marked
     * removed. Not while a page still uses it: that would leave the field
     * pointing at nothing.
     */
    public function remove(string $id, CompStore $comps): JsonResponse
    {
        $image = $this->images->find($id) ?? abort(404);

        abort_unless($image->is(StockImage::PREVIEW) || $image->is(StockImage::FAILED), 422, __('Only a preview can be removed.'));
        abort_if($image->usages() !== [], 422, __('A page still uses this preview. Take it out of the page first, then remove it.'));

        $comps->forget($image->comp());
        $asset = AssetContainer::find($image->asset->volume)?->asset($image->asset->path);
        $asset ? $asset->delete() : $this->images->removed($image->id);

        return response()->json(['message' => __('Preview removed.'), 'stock' => $this->presenter->summary($this->images->get($id))]);
    }
}
