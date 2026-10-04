<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Unit;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use PHPUnit\Framework\TestCase;

/**
 * The guide's port of core's QuoteFinder (resources/js/suggest/quote.js)
 * is tested in Node against a copy of core's cases. The copy must be
 * core's own, so the two can't drift.
 */
final class QuoteCasesTest extends TestCase
{
    public function test_the_copy_is_cores_own(): void
    {
        $core = (new \ReflectionClass(QuoteFinder::class))->getFileName();
        $cases = dirname((string) $core, 3).'/resources/anchor/quote-cases.json';

        $this->assertFileExists($cases);
        $this->assertJsonFileEqualsJsonFile($cases, dirname(__DIR__, 2).'/resources/js/suggest/quote-cases.json', 'Copy core\'s resources/anchor/quote-cases.json to resources/js/suggest/.');
    }
}
