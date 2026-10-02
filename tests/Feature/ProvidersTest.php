<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use NineteenNinetyFour\Ghostwriter\Ai\ConfigCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Jobs\SuggestKinds;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * How the addon connects Ghostwriter Core: where keys come from, which
 * provider is chosen and how long a job may take.
 */
class ProvidersTest extends TestCase
{
    public function test_keys_come_from_the_config_then_from_the_old_ai_config(): void
    {
        $keys = new ConfigCredentials;

        $this->assertSame('test-key', $keys->key('anthropic'));
        $this->assertNull($keys->key('openai'));

        // A site that kept its key in config/ai.php, as the Laravel AI SDK had it.
        config(['ai.providers.openai.key' => ' sk-old ']);
        $this->assertSame('sk-old', $keys->key('openai'));

        config(['ghostwriter.keys.openai' => 'sk-new']);
        $this->assertSame('sk-new', $keys->key('openai'));

        // The photo libraries' keys stay where they were.
        config(['ghostwriter.images.unsplash_key' => 'unsplash-key']);
        $this->assertSame('unsplash-key', $keys->key('unsplash'));
    }

    public function test_the_key_status_names_the_variables_and_never_the_keys(): void
    {
        $this->app->make(Providers::class)->unfake();
        config(['ghostwriter.keys.gemini' => 'gemini-key']);

        $status = $this->app->make(Providers::class)->keyStatus();

        $this->assertTrue($status['ANTHROPIC_API_KEY']);
        $this->assertFalse($status['OPENAI_API_KEY']);
        $this->assertTrue($status['GEMINI_API_KEY']);
        $this->assertNotContains('gemini-key', $status);
    }

    public function test_images_are_made_with_openai_or_gemini_only(): void
    {
        $this->app->make(Providers::class)->unfake();
        config(['ghostwriter.keys.gemini' => 'gemini-key']);

        $this->assertSame('gemini', app(ImageStudio::class)->provider());

        // xAI no longer makes images for Ghostwriter; a site that chose it
        // gets whichever provider has a key.
        config(['ghostwriter.images.provider' => 'openai']);
        $this->assertSame('openai', app(Settings::class)->imageProvider());

        config(['ghostwriter.images.provider' => 'xai']);
        $this->assertNull(app(Settings::class)->imageProvider());
        $this->assertSame('gemini', app(ImageStudio::class)->provider());
    }

    public function test_a_job_is_given_time_for_retries(): void
    {
        Bus::fake([SuggestKinds::class]);

        config(['ghostwriter.timeout' => 300]);
        SuggestKinds::start(['articles']);

        Bus::assertDispatchedAfterResponse(SuggestKinds::class, fn (SuggestKinds $job) => $job->timeout === 300 * 3 + 60);
    }
}
