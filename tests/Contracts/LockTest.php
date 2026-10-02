<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\LockTimeout;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\LockContract;
use NineteenNinetyFour\Ghostwriter\Storage\FileLock;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's LockContract, run against the addon's own FileLock as the
 * container gives it, in the test's throwaway directory.
 */
final class LockTest extends TestCase
{
    use LockContract;

    protected function contractLock(): Lock
    {
        return app(Lock::class);
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(FileLock::class, $this->contractLock());
    }

    public function test_a_lock_held_by_another_process_is_waited_on_then_given_up(): void
    {
        $id = Format::Statamic->newId();
        $path = config('ghostwriter.sessions_path').'/'.$id.'.lock';
        @mkdir(dirname($path), 0777, true);

        // Another PHP process takes the session's lock and holds it a while.
        $child = proc_open([PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); echo "held\n"; sleep(3);', $path], [1 => ['pipe', 'w']], $pipes);
        $this->assertSame("held\n", fgets($pipes[1]));

        $started = microtime(true);

        try {
            $this->contractLock()->run('session:'.$id, fn () => $this->fail('The work ran while another process held the lock.'), 1);
            $this->fail('The lock should not have been had.');
        } catch (LockTimeout) {
            $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started);
        } finally {
            proc_terminate($child);
            proc_close($child);
        }

        // Let go by the other process, it is had at once.
        $this->assertSame('ran', $this->contractLock()->run('session:'.$id, fn () => 'ran', 5));
        $this->assertFileExists($path, 'A session\'s lock sits beside it.');
    }
}
