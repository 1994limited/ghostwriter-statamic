<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\StockImageStoreContract;
use NineteenNinetyFour\Ghostwriter\Storage\FileStockImageStore;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's StockImageStoreContract, run against the addon's own
 * FileStockImageStore as the container gives it, in the test's throwaway
 * directory.
 */
final class StockImageStoreTest extends TestCase
{
    use StockImageStoreContract;

    protected function stockImageStore(): StockImageStore
    {
        return app(StockImageStore::class);
    }

    protected function storeFormat(): Format
    {
        return Format::Statamic;
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(FileStockImageStore::class, $this->stockImageStore());
    }

    public function test_each_record_is_one_yaml_file_in_the_stock_path(): void
    {
        $image = $this->stockImageStore()->save($this->preview(1, '123', new \DateTimeImmutable('2026-10-02T10:00:00+00:00')));

        $this->assertFileExists($this->workspace.'/content/stock/'.$image->id.'.yaml');
        $this->assertStringContainsString('library: getty', (string) file_get_contents($this->workspace.'/content/stock/'.$image->id.'.yaml'));
    }
}
