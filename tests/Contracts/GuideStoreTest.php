<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\GuideStoreContract;
use NineteenNinetyFour\Ghostwriter\Storage\FileGuideStore;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's GuideStoreContract, run against the addon's own FileGuideStore as the
 * container gives it, in the test's throwaway directory.
 */
final class GuideStoreTest extends TestCase
{
    use GuideStoreContract;

    protected function guideStore(): GuideStore
    {
        return app(GuideStore::class);
    }

    protected function storeFormat(): Format
    {
        return Format::Statamic;
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(FileGuideStore::class, $this->guideStore());
    }
}
