<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use Illuminate\Support\Carbon;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SessionStoreContract;
use NineteenNinetyFour\Ghostwriter\Storage\FileSessionStore;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's SessionStoreContract, run against the addon's own FileSessionStore as the
 * container gives it, in the test's throwaway directory.
 */
final class SessionStoreTest extends TestCase
{
    use SessionStoreContract;

    protected function setUp(): void
    {
        parent::setUp();

        // The contract compares the stamp with the real time, so the clock
        // is never held still here, even when recording requests.
        Carbon::setTestNow();
    }

    protected function sessionStore(): SessionStore
    {
        return app(SessionStore::class);
    }

    protected function storeFormat(): Format
    {
        return Format::Statamic;
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(FileSessionStore::class, $this->sessionStore());
    }
}
