<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The preview locator is core's, copied as it is (core's docs/preview.md):
 * the panel's copy must match the core version installed.
 */
final class LocatorCopyTest extends TestCase
{
    public function test_the_panels_locator_is_cores_exactly(): void
    {
        $core = __DIR__.'/../../vendor/1994/ghostwriter-core/resources/js/preview/locator.js';
        $ours = __DIR__.'/../../resources/js/preview/locator.js';

        $this->assertFileExists($core);
        $this->assertSame(hash_file('sha256', $core), hash_file('sha256', $ours), 'resources/js/preview/locator.js differs from core\'s: copy it again (cp vendor/1994/ghostwriter-core/resources/js/preview/locator.js resources/js/preview/).');
    }
}
