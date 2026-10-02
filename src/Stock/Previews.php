<?php

namespace NineteenNinetyFour\Ghostwriter\Stock;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\StandIn;
use NineteenNinetyFour\Ghostwriter\Images\FieldSlot;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use Statamic\Contracts\Assets\Asset;

/**
 * "Insert preview": a paid photo put into a field before it is licensed.
 *
 * - The field gets a **stand-in** asset: a striped JPEG at the photo's
 *   aspect ratio, labelled "Getty Images 1234567 · preview, not licensed".
 *   It holds nothing of the library's, so it is safe at a public address,
 *   and it already has the title, alt text and file name the licensed
 *   photo keeps.
 * - The library's watermarked **comp** is kept privately for signed-in
 *   editors (CompStore), as long as the library's terms allow, or, where
 *   they allow no storage at all, only its own preview address is noted.
 *   It is never an asset.
 * - The ledger records it as a `preview`, used in this field.
 */
class Previews
{
    public function __construct(private Ledger $ledger, private CompStore $comps) {}

    /**
     * @param  string  $field  The field's path on the form.
     * @return array{asset: Asset, record: StockImage}
     */
    public function insert(FieldSlot $slot, LicensableLibrary $library, string $id, string $field, string $term = ''): array
    {
        // Looked up again by ID: never what the browser sent.
        $photo = $library->photo($id);
        $preview = $library->preview($id);
        $storage = $library->capabilities()->previewStorage ?? Capabilities::STORAGE_PRIVATE;

        $comp = match (true) {
            $storage === Capabilities::STORAGE_NONE => $preview->url,
            $preview->file !== null => $this->comps->put($preview->file),
            default => null,
        };

        $fallback = trim($term) !== '' ? $term : $slot->title;
        $standIn = StandIn::jpeg($photo->width ?? 1600, $photo->height ?? 1067, self::label($library->label(), $photo->id));

        $asset = ImageStudio::saveAsset($slot->field['container'], $slot->folder(), $photo->filenameBase($fallback).'-'.$library->id().'-'.$photo->id, $standIn, 'jpg', [
            'title' => $photo->assetTitle($fallback),
            'alt' => $photo->alt($fallback),
            'credit' => $photo->credit,
            'credit_url' => $photo->creditUrl,
            'licence' => $photo->licence,
        ]);

        $record = $this->ledger->images()->recordPreview(
            $photo,
            Ledger::ref($asset),
            $comp,
            $comp !== null ? $preview->keepUntil : null,
            Ledger::person(),
            $slot->entry ? Ledger::usage($slot->entry, $field, $slot->label()) : null,
            $library->capabilities()->noModelInput,
        );

        $this->ledger->mark($asset, $record);

        return ['asset' => $asset, 'record' => $record];
    }

    /**
     * What the stand-in says: "Getty Images 1234567 · preview, not licensed".
     */
    public static function label(string $library, string $id): string
    {
        return "{$library} {$id} · preview, not licensed";
    }
}
