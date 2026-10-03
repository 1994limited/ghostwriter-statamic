<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use NineteenNinetyFour\Ghostwriter\Ai\ConfigCredentials;
use NineteenNinetyFour\Ghostwriter\Ai\ConfigProviderSettings;
use NineteenNinetyFour\Ghostwriter\Ai\EncryptedProviderKeys;
use NineteenNinetyFour\Ghostwriter\Ai\ModelCheck;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\ConnectsProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\OpenRouterConnection;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderKeys;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeOpenRouter;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Addon;
use Statamic\Facades\CP\Toast;

/**
 * "Connect with OpenRouter", against core's FakeOpenRouter (its sign-in
 * sends the browser straight back with a code bound to the PKCE
 * challenge). OpenRouter itself is never called. Core's
 * docs/connecting-accounts.md: state and verifier kept together, single
 * use; the key encrypted; .env always wins; managers only.
 */
class OpenRouterTest extends TestCase
{
    private FakeOpenRouter $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://cms.example.com', 'ghostwriter.keys.openrouter' => null]);

        $this->connection = new FakeOpenRouter(app(ProviderKeys::class), new ConfigCredentials);
        $this->app->instance(ConnectsProvider::class, $this->connection);
        Toast::clear();
    }

    public function test_the_key_store_is_encrypted_and_the_real_connection_is_bound(): void
    {
        $this->assertInstanceOf(EncryptedProviderKeys::class, app(ProviderKeys::class));

        $this->app->forgetInstance(ConnectsProvider::class);
        $this->assertInstanceOf(OpenRouterConnection::class, app(ConnectsProvider::class));
    }

    public function test_connect_sends_the_person_to_openrouter_with_the_callback_and_a_challenge(): void
    {
        $this->app->forgetInstance(ConnectsProvider::class);
        $this->signInAsManager();

        $location = (string) $this->get(cp_route('ghostwriter.providers.connect', 'openrouter'))->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://openrouter.ai/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('https://cms.example.com/cp/ghostwriter/providers/openrouter/callback', $query['callback_url']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertSame('Ghostwriter (cms.example.com)', $query['key_label']);

        // The state and the verifier are kept together, and the verifier never leaves.
        $kept = session('ghostwriter.connect.openrouter');
        $this->assertSame($kept['state'], $query['state']);
        $this->assertStringNotContainsString($kept['verifier'], $location);
    }

    public function test_connecting_keeps_the_key_encrypted_and_the_settings_row_says_so(): void
    {
        $this->signInAsManager();

        $location = (string) $this->get(cp_route('ghostwriter.providers.connect', 'openrouter'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://cms.example.com/cp/ghostwriter/providers/openrouter/callback?code=', $location);

        $this->get($location)->assertRedirect(app(Settings::class)->url());
        $this->assertTrue($this->connection->connected());
        $this->assertStringStartsWith('Connected to OpenRouter (sk-or-v1-', $this->toasts()[0]);

        $file = (string) file_get_contents(EncryptedProviderKeys::path());
        $this->assertStringNotContainsString(FakeOpenRouter::KEY, $file, 'Encrypted at rest.');
        $this->assertSame(FakeOpenRouter::KEY, app(ProviderKeys::class)->get('openrouter'));

        // Single use.
        $this->get($location)->assertForbidden();

        // Check connection: the credit left.
        $this->postJson(cp_route('ghostwriter.providers.check', 'openrouter'))->assertOk()->assertJsonPath('ok', true)->assertJsonPath('message', $this->connection->account()->summary());

        $row = $this->row();
        $this->assertStringContainsString('Connected to OpenRouter (', $row);
        $this->assertStringContainsString('Disconnect', $row);
        $this->assertStringContainsString('pass through OpenRouter', $row);
        $this->assertStringNotContainsString(FakeOpenRouter::KEY, $row);

        $this->postJson(cp_route('ghostwriter.providers.disconnect', 'openrouter'))->assertOk()->assertJsonPath('message', 'OpenRouter is disconnected. To revoke the key, delete it at openrouter.ai/settings/keys.');
        $this->assertFalse($this->connection->connected());
        $this->assertNull(app(ProviderKeys::class)->get('openrouter'));
        $this->assertStringContainsString('Connect with OpenRouter', $this->row());
    }

    public function test_a_wrong_or_reused_state_is_refused_and_nothing_is_kept(): void
    {
        $this->signInAsManager();

        $location = (string) $this->get(cp_route('ghostwriter.providers.connect', 'openrouter'))->headers->get('Location');

        $this->get(preg_replace('/state=[^&]+/', 'state=forged', $location))->assertForbidden();
        $this->assertFalse($this->connection->connected());

        // The state went with the forged attempt.
        $this->get($location)->assertForbidden();
        $this->assertFalse($this->connection->connected());

        // Nor without starting from Connect at all.
        $this->get(cp_route('ghostwriter.providers.callback', 'openrouter').'?code=x&state=y')->assertForbidden();
    }

    public function test_a_refused_or_cancelled_sign_in_says_so(): void
    {
        $this->signInAsManager();
        $this->connection->refuseCode = true;

        $location = (string) $this->get(cp_route('ghostwriter.providers.connect', 'openrouter'))->headers->get('Location');
        $this->get($location)->assertRedirect(app(Settings::class)->url());

        $this->assertFalse($this->connection->connected());
        $this->assertSame([OpenRouterConnection::SIGN_IN_REFUSED], $this->toasts());

        Toast::clear();
        $location = (string) $this->get(cp_route('ghostwriter.providers.connect', 'openrouter'))->headers->get('Location');
        $this->get(preg_replace('/code=[^&]+&?/', '', $location))->assertRedirect(app(Settings::class)->url());
        $this->assertSame(['OpenRouter isn\'t connected: the sign-in was cancelled.'], $this->toasts());
    }

    public function test_a_key_in_env_always_wins(): void
    {
        config(['ghostwriter.keys.openrouter' => 'sk-or-v1-from-env']);
        $this->signInAsManager();

        $this->get(cp_route('ghostwriter.providers.connect', 'openrouter'))->assertRedirect(app(Settings::class)->url());
        $this->assertSame([OpenRouterConnection::ENV_KEY_SET], $this->toasts());
        $this->assertNull(session('ghostwriter.connect.openrouter'));

        $this->postJson(cp_route('ghostwriter.providers.disconnect', 'openrouter'))->assertStatus(409)->assertJsonPath('message', OpenRouterConnection::ENV_KEY_SET);

        $row = $this->row();
        $this->assertStringContainsString('Using OPENROUTER_API_KEY from .env', $row);
        $this->assertStringNotContainsString('Connect with OpenRouter', $row);
        $this->assertStringNotContainsString('sk-or-v1-from-env', $row);
        $this->assertTrue(app(Settings::class)->keyStatus()['OPENROUTER_API_KEY']);
    }

    public function test_only_people_who_manage_the_settings_may_connect(): void
    {
        $this->signIn();

        $this->get(cp_route('ghostwriter.providers.connect', 'openrouter'))->assertForbidden();
        $this->get(cp_route('ghostwriter.providers.callback', 'openrouter').'?code=x&state=y')->assertForbidden();
        $this->postJson(cp_route('ghostwriter.providers.disconnect', 'openrouter'))->assertForbidden();
        $this->postJson(cp_route('ghostwriter.providers.check', 'openrouter'))->assertForbidden();

        $this->signInAsManager();
        $this->get(cp_route('ghostwriter.providers.connect', 'anthropic'))->assertNotFound();
    }

    public function test_openrouter_is_a_provider_for_writing_and_images_with_a_model_per_tier(): void
    {
        config(['ghostwriter.provider' => null]);
        Addon::get(Settings::ADDON)->settings()->set(['provider' => 'openrouter', 'image_provider' => 'openrouter', 'openrouter_quick_model' => 'google/gemini-3.8-flash'])->save();

        $settings = app(Settings::class);
        $tiers = new ConfigProviderSettings($settings);

        $this->assertSame('openrouter', $settings->provider());
        $this->assertSame('openrouter', $settings->imageProvider());
        $this->assertSame('google/gemini-3.8-flash', $tiers->tierModel('openrouter', 'quick'));
        $this->assertNull($tiers->tierModel('openrouter', 'writing'));
        $this->assertNull($tiers->tierModel('anthropic', 'quick'));

        // Config wins over the settings screen.
        config(['ghostwriter.openrouter.models.writing' => 'openai/gpt-6.1-sol']);
        $this->assertSame('openai/gpt-6.1-sol', $tiers->tierModel('openrouter', 'writing'));

        // The settings screen offers the tiers' models, and the OpenRouter row.
        $fields = collect(Addon::get(Settings::ADDON)->settingsBlueprint()->fields()->all());
        $this->assertArrayHasKey('anthropic/claude-opus-5.5', $fields['openrouter_quick_model']->get('options'));
        $this->assertArrayHasKey('openrouter', $fields['provider']->get('options'));
        $this->assertStringContainsString('Connect with OpenRouter', (string) $fields['openrouter_connection']->get('html'));

        // A model that isn't an OpenRouter id is said out loud.
        $this->assertStringContainsString('isn\'t an OpenRouter model id', (string) app(ModelCheck::class)->mismatch('openrouter', 'claude-opus-5-5'));
        $this->assertNull(app(ModelCheck::class)->mismatch('openrouter', 'anthropic/claude-opus-5.5'));
    }

    /**
     * The settings screen's OpenRouter row, as HTML.
     */
    private function row(): string
    {
        $contents = app(Settings::class)->withOpenRouter(['tabs' => ['main' => ['sections' => [['display' => 'AI provider', 'fields' => []]]]]], app(ConnectsProvider::class));

        return (string) $contents['tabs']['main']['sections'][0]['fields'][0]['field']['html'];
    }

    /**
     * @return array<int, string>
     */
    private function toasts(): array
    {
        return array_values(array_map(fn ($toast) => $toast['message'], Toast::toArray()));
    }
}
