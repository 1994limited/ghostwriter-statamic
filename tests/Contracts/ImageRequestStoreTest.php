<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\ImageRequestStoreContract;
use NineteenNinetyFour\Ghostwriter\Storage\FileImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's ImageRequestStoreContract, run against the addon's own FileImageRequestStore as the
 * container gives it, in the test's throwaway directory.
 */
final class ImageRequestStoreTest extends TestCase
{
    use ImageRequestStoreContract;

    protected function imageRequestStore(): ImageRequestStore
    {
        return app(ImageRequestStore::class);
    }

    protected function storeFormat(): Format
    {
        return Format::Statamic;
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(FileImageRequestStore::class, $this->imageRequestStore());
    }
}
