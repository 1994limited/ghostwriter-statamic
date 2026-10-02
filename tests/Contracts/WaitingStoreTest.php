<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\WaitingStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\WaitingStoreContract;
use NineteenNinetyFour\Ghostwriter\Storage\FileWaitingStore;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's WaitingStoreContract, run against the addon's own FileWaitingStore as the
 * container gives it, in the test's throwaway directory.
 */
final class WaitingStoreTest extends TestCase
{
    use WaitingStoreContract;

    protected function waitingStore(): WaitingStore
    {
        return app(WaitingStore::class);
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(FileWaitingStore::class, $this->waitingStore());
    }
}
