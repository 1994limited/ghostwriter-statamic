<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\KindStoreContract;
use NineteenNinetyFour\Ghostwriter\Storage\FileKindStore;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's KindStoreContract, run against the addon's own FileKindStore as the
 * container gives it, in the test's throwaway directory.
 */
final class KindStoreTest extends TestCase
{
    use KindStoreContract;

    protected function kindStore(): KindStore
    {
        return app(KindStore::class);
    }

    protected function storeFormat(): Format
    {
        return Format::Statamic;
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(FileKindStore::class, $this->kindStore());
    }
}
