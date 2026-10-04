<?php

namespace NineteenNinetyFour\Ghostwriter\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetAlt;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Asset;

/**
 * An asset's alt text, where Statamic keeps it: the `alt` field of its
 * container's blueprint. A container without one has nowhere to keep alt
 * text, so there's nothing to ask for (null).
 *
 * save() is "Save to the image": the one change Suggest edits saves
 * itself, after a confirm, because alt text belongs to the asset and shows
 * wherever it's used.
 */
class StatamicAssetAlt implements AssetAlt
{
    public function altFor(AssetRef $asset): ?string
    {
        $found = $this->asset($asset);

        if ($found === null || ! $found->container()->blueprint()?->hasField('alt')) {
            return null;
        }

        $alt = $found->get('alt');

        return is_scalar($alt) ? trim((string) $alt) : '';
    }

    public function asset(AssetRef $asset): ?AssetContract
    {
        if ($asset->volume === '' || $asset->path === '') {
            return null;
        }

        $found = Asset::find($asset->volume.'::'.$asset->path);

        return $found instanceof AssetContract ? $found : null;
    }

    /**
     * Writes the alt text on the asset, and gives what it was, for Undo.
     */
    public function save(AssetContract $asset, string $alt): string
    {
        $before = $asset->get('alt');
        $asset->set('alt', trim($alt) === '' ? null : trim($alt))->save();

        return is_scalar($before) ? (string) $before : '';
    }
}
