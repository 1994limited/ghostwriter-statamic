<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

/**
 * A model call can take a minute or more, which is longer than a web request
 * should be held open. With a real queue the job is queued as usual. On the
 * sync driver, the default for a flat-file site, it runs after the response
 * has been sent, in the same PHP process, and the screen polls for the result.
 */
trait RunsInBackground
{
    public static function start(mixed ...$arguments): void
    {
        if (config('queue.default') === 'sync') {
            static::dispatchAfterResponse(...$arguments);

            return;
        }

        static::dispatch(...$arguments);
    }

    protected function allowTimeToFinish(): void
    {
        ignore_user_abort(true);
        @set_time_limit((int) config('ghostwriter.timeout', 180) + 30);
    }
}
