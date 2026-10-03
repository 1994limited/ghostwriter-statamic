<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetRefs;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlaceholderAssets;
use NineteenNinetyFour\Ghostwriter\Core\Images\AssetSink;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PlaceholderAssetsContract;
use NineteenNinetyFour\Ghostwriter\Gaps\StatamicAssetRefs;
use NineteenNinetyFour\Ghostwriter\Gaps\StatamicPlaceholderAssets;
use NineteenNinetyFour\Ghostwriter\Images\ContainerAssetSink;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\AssetContainer;

/**
 * Core's PlaceholderAssetsContract against the addon's own ports: the
 * placeholder ContainerAssetSink saves is recognised by its path, and an
 * ordinary image titled like it is not.
 */
final class PlaceholderAssetsTest extends TestCase
{
    use PlaceholderAssetsContract;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->workspace.'/assets', 'url' => '/assets']]);
        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();
    }

    protected function placeholderSink(): AssetSink
    {
        return new ContainerAssetSink;
    }

    protected function placeholderAssets(): PlaceholderAssets
    {
        return new StatamicPlaceholderAssets;
    }

    protected function placeholderAssetRefs(): AssetRefs
    {
        return new StatamicAssetRefs;
    }

    protected function placeholderImageField(): Field
    {
        return Field::fromSpec(['handle' => 'hero_image', 'type' => 'assets', 'kind' => 'reference', 'display' => 'Hero image', 'images' => true, 'container' => 'assets', 'max_files' => 1]);
    }

    protected function ordinaryImageTitledLikeThePlaceholder(Field $field): mixed
    {
        Storage::disk('assets')->put('ghostwriter/our-garden.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        AssetContainer::find('assets')->makeAsset('ghostwriter/our-garden.png')->data(['title' => Placeholders::TITLE])->save();

        return 'ghostwriter/our-garden.png';
    }
}
