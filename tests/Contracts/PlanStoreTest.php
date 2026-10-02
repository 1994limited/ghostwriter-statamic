<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PlanStoreContract;
use NineteenNinetyFour\Ghostwriter\Storage\FilePlanStore;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's PlanStoreContract, run against the addon's own FilePlanStore as the
 * container gives it, in the test's throwaway directory.
 */
final class PlanStoreTest extends TestCase
{
    use PlanStoreContract;

    protected function planStore(): PlanStore
    {
        return app(PlanStore::class);
    }

    protected function storeFormat(): Format
    {
        return Format::Statamic;
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(FilePlanStore::class, $this->planStore());
    }
}
