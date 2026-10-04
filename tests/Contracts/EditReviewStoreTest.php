<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\EditReviewStoreContract;
use NineteenNinetyFour\Ghostwriter\Storage\FileEditReviewStore;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's EditReviewStoreContract against the addon's JSON files, in the
 * test's throwaway directory.
 */
final class EditReviewStoreTest extends TestCase
{
    use EditReviewStoreContract;

    protected function editReviewStore(): EditReviewStore
    {
        return app(EditReviewStore::class);
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(FileEditReviewStore::class, $this->editReviewStore());
    }
}
