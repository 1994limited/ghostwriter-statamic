<?php

namespace NineteenNinetyFour\Ghostwriter\Testing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeScenario;
use RuntimeException;

/**
 * Scripted model replies for the end-to-end tests (the ghostwriter-e2e
 * repository), so a browser can drive the control panel without spending
 * tokens. Off unless all of these hold:
 *
 * - `ghostwriter.testing.fake_scenarios` (GHOSTWRITER_FAKE_SCENARIOS) is the
 *   folder of scenario files; it is null by default;
 * - the app's environment is `local` or `testing`, and never production;
 * - the request names a scenario: the X-Ghostwriter-Fake header, or the
 *   ghostwriter_fake cookie.
 *
 * A request that names one then has core's FakeProvider stand in for every
 * model (FakeScenario), and every job it queues carries the name in its
 * payload, so the queue worker plays the same scenario for that job and
 * goes back to the real providers after it. Requests without the header,
 * such as an editor's own browser on the same site, are untouched.
 */
final class FakeScenarios
{
    /** The key a queued job's payload carries the scenario under. */
    public const PAYLOAD = 'ghostwriterFake';

    /** The scenario playing in this process, if any. */
    private static ?string $current = null;

    /** The scenario the web request named, played again after a job run in it (the sync queue). */
    private static ?string $request = null;

    public static function enabled(Application $app): bool
    {
        $dir = config('ghostwriter.testing.fake_scenarios');

        return is_string($dir) && $dir !== '' && ! $app->isProduction() && $app->environment(['local', 'testing']);
    }

    public static function register(Application $app): void
    {
        if (! self::enabled($app)) {
            return;
        }

        if (! $app->runningInConsole() && $app->bound('request') && $app->make('request') instanceof Request) {
            self::fromRequest($app, $app->make('request'));
        }

        Queue::createPayloadUsing(fn () => self::$current !== null ? [self::PAYLOAD => self::$current] : []);

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($app): void {
            $value = $event->job->payload()[self::PAYLOAD] ?? null;

            is_string($value) ? self::play($app, $value) : self::stop($app);
        });

        Event::listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], function () use ($app): void {
            self::$request !== null ? self::play($app, self::$request) : self::stop($app);
        });
    }

    /**
     * Play the scenario a request names in its header or cookie, if any.
     * The cookie is read as sent: the tests set it, not the app.
     */
    public static function fromRequest(Application $app, Request $request): void
    {
        if (! self::enabled($app)) {
            return;
        }

        $value = $request->headers->get(FakeScenario::HEADER) ?? self::rawCookie((string) $request->headers->get('Cookie', ''));

        if ($value !== null && $value !== '') {
            self::play($app, self::$request = $value);
        }
    }

    /**
     * Stand the scenario in. A name that isn't one fails loudly rather than
     * falling back to the real providers and spending tokens.
     */
    private static function play(Application $app, string $value): void
    {
        $dir = (string) config('ghostwriter.testing.fake_scenarios');

        if (FakeScenario::path($dir, $value) === null) {
            throw new RuntimeException('Ghostwriter: there is no fake scenario "'.mb_substr($value, 0, 100).'" in '.$dir.'.');
        }

        self::$current = $value;
        $app->make(Providers::class)->fake(FakeScenario::load($dir, $value, function (string $key): int {
            Cache::add($key, 0, 3600);

            return (int) Cache::increment($key) - 1;
        }));
    }

    /**
     * Whether a scenario is playing in this process: Connections then
     * checks keys with core's FakeKeyCheck and shows its test services.
     */
    public static function playing(): bool
    {
        return self::$current !== null;
    }

    /** Forget everything, for tests of this class. */
    public static function flush(): void
    {
        self::$current = self::$request = null;
        Queue::createPayloadUsing(null);
    }

    /** The scenario cookie from a raw Cookie header, before the app decrypts cookies. */
    private static function rawCookie(string $header): ?string
    {
        foreach (explode(';', $header) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

            if ($name === FakeScenario::COOKIE) {
                return urldecode($value);
            }
        }

        return null;
    }

    private static function stop(Application $app): void
    {
        if (self::$current === null) {
            return;
        }

        self::$current = null;
        $app->make(Providers::class)->unfake();
    }
}
