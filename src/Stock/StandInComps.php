<?php

namespace NineteenNinetyFour\Ghostwriter\Stock;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;

/**
 * Stock previews whose comp is still held: each stand-in's file name, with
 * the stock record's id and the comp's address. Live Preview swaps a
 * stand-in for its comp for signed-in editors (StockPreviewsInLivePreview),
 * and the page preview's map lists the record's id beside the file name,
 * so the swapped image is still found by its address
 * (`…/stock/<id>/comp`).
 */
class StandInComps
{
    public function __construct(private StockImageStore $store) {}

    /**
     * @return array<string, array{id: string, url: string}> By the stand-in's file name.
     */
    public function all(): array
    {
        $comps = [];

        foreach ($this->store->query(new StockImageQuery([StockImage::PREVIEW, StockImage::LICENSING, StockImage::FAILED], perPage: 500))->images as $image) {
            if ($image->comp() !== null) {
                $comps[basename($image->asset->path)] = ['id' => $image->id, 'url' => cp_route('ghostwriter.stock.comp', $image->id)];
            }
        }

        return $comps;
    }
}
