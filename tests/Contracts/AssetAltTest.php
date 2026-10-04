<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetAlt;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\AssetAltContract;
use NineteenNinetyFour\Ghostwriter\Suggest\StatamicAssetAlt;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;

/**
 * Core's AssetAltContract against StatamicAssetAlt: alt text is the
 * container blueprint's `alt` field, and a container whose blueprint has
 * none has nowhere to keep it.
 */
final class AssetAltTest extends TestCase
{
    use AssetAltContract;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['assets', 'logos'] as $handle) {
            config(["filesystems.disks.{$handle}" => ['driver' => 'local', 'root' => $this->workspace.'/'.$handle, 'url' => '/'.$handle]]);
            Storage::fake($handle);
            AssetContainer::make($handle)->disk($handle)->save();
        }

        Blueprint::make('logos')->setNamespace('assets')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();

        foreach ([['assets', 'with.jpg', 'A gravel path between box hedges'], ['assets', 'without.jpg', null], ['logos', 'logo.png', null]] as [$container, $path, $alt]) {
            Storage::disk($container)->put($path, 'image');
            Asset::make()->container($container)->path($path)->data(array_filter(['alt' => $alt]))->save();
        }
    }

    protected function assetAlt(): AssetAlt
    {
        return app(AssetAlt::class);
    }

    protected function assetWithAlt(): AssetRef
    {
        return AssetRef::statamic('assets', 'with.jpg');
    }

    protected function assetWithoutAlt(): AssetRef
    {
        return AssetRef::statamic('assets', 'without.jpg');
    }

    protected function assetWithNoAltField(): AssetRef
    {
        return AssetRef::statamic('logos', 'logo.png');
    }

    public function test_the_addon_binds_its_own_and_saves_to_the_asset(): void
    {
        $alt = app(AssetAlt::class);
        $this->assertInstanceOf(StatamicAssetAlt::class, $alt);

        $before = $alt->save($alt->asset($this->assetWithoutAlt()), 'Stone samples laid out for a terrace');

        $this->assertSame('', $before);
        $this->assertSame('Stone samples laid out for a terrace', $alt->altFor($this->assetWithoutAlt()));
        $this->assertNull($alt->altFor(AssetRef::statamic('assets', 'gone.jpg')));
    }

    protected function tearDown(): void
    {
        Blueprint::find('assets.logos')?->delete();

        parent::tearDown();
    }
}
