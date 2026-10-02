<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Contracts\Bus\Dispatcher;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\Waiting;
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

        // Marked until a worker starts it, so a screen waiting on it can
        // tell when no worker is running.
        if ($subject = $job->subject()) {
            app(Waiting::class)->queued($subject);
        }

        if (config('queue.default') === 'sync') {
            app(Dispatcher::class)->dispatchAfterResponse($job);

            return;
        }

        dispatch($job);
    }

    /**
     * What the job works on, for screens waiting on it ("session:01J…"), so
     * they can tell when no worker has picked it up. Null for work nothing
     * waits on.
     */
    public function subject(): ?string
    {
        return null;
    }

    /**
     * Called first thing in each job's handle(): the work has started.
     */
    protected function allowTimeToFinish(): void
    {
        if ($subject = $this->subject()) {
            app(Waiting::class)->started($subject);
        }

        ignore_user_abort(true);
        @set_time_limit(max($this->timeout, app(Settings::class)->jobTimeout()));
    }
}
