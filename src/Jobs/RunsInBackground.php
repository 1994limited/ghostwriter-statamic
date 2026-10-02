<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Contracts\Bus\Dispatcher;
use NineteenNinetyFour\Ghostwriter\Settings;

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
        $job = new static(...$arguments);

        // A call that finds the provider busy is tried up to three times, so
        // a job is given three times the timeout and a minute to spare, and
        // never less than it declares.
        $job->timeout = max($job->timeout, app(Settings::class)->jobTimeout());

        if (config('queue.default') === 'sync') {
            app(Dispatcher::class)->dispatchAfterResponse($job);

            return;
        }

        dispatch($job);
    }

    protected function allowTimeToFinish(): void
    {
        ignore_user_abort(true);
        @set_time_limit(max($this->timeout, app(Settings::class)->jobTimeout()));
    }
}
