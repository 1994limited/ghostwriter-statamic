<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\RevisitStoreContract;
use NineteenNinetyFour\Ghostwriter\Storage\FileRevisitStore;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's RevisitStoreContract against the addon's JSON shards, one per
 * site and collection.
 */
final class RevisitStoreTest extends TestCase
{
    use RevisitStoreContract;

    protected function revisitStore(): RevisitStore
    {
        return app(RevisitStore::class);
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(FileRevisitStore::class, $this->revisitStore());
    }
}
