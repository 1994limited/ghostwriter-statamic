<?php

namespace NineteenNinetyFour\Ghostwriter\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlaceholderAssets;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Images\ContainerAssetSink;

/**
 * Ghostwriter's striped placeholder on a Statamic site: the asset at
 * `ghostwriter/image-placeholder.png` in any container, where
 * ContainerAssetSink puts it. By path, never by title.
 */
class StatamicPlaceholderAssets implements PlaceholderAssets
{
    public function isPlaceholder(AssetRef $asset, Field $field): bool
    {
        return ltrim($asset->path, '/') === ContainerAssetSink::PATH;
    }
}
