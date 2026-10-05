<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\DB;
use NineteenNinetyFour\Ghostwriter\Ai\EncryptedProviderKeys;
use NineteenNinetyFour\Ghostwriter\Connections\ConnectionsPage;
use NineteenNinetyFour\Ghostwriter\Connections\EncryptedCredentialStore;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Connections\ChecksKeys;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Connections;
use NineteenNinetyFour\Ghostwriter\Core\Connections\CredentialStore;
use NineteenNinetyFour\Ghostwriter\Core\Connections\KeyWatch;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Testing\FakeKeyCheck;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Ports\LibraryTokens;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Onboarding;
use NineteenNinetyFour\Ghostwriter\Stock\EncryptedLibraryTokens;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Settings → Connections: a card per service, set up by pasting a key
 * that is checked first and kept encrypted; .env always wins.
 */
class ConnectionsTest extends TestCase
{
    private const KEY = 'pexels-pasted-0123456789wxyz';

    private FakeKeyCheck $check;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ghostwriter.images.pexels_key' => null, 'ghostwriter.images.unsplash_key' => null]);
        $this->check = new FakeKeyCheck;
        $this->app->instance(ChecksKeys::class, $this->check);
    }

    public function test_the_page_shows_every_service_by_group(): void
    {
        $this->signInAsManager();

        $this->get(cp_route('ghostwriter.connections.show'))->assertOk();
        $payload = app(ConnectionsPage::class)->payload();

        $this->assertSame(['writing', 'images', 'stock'], array_column($payload['groups'], 'id'));
        $this->assertSame(['anthropic', 'openai', 'gemini', 'openrouter'], array_column($payload['groups'][0]['cards'], 'id'));
        $this->assertSame(['unsplash', 'pexels', 'pixabay', 'openverse'], array_column($payload['groups'][1]['cards'], 'id'));
        $this->assertSame(['shutterstock'], array_column($payload['groups'][2]['cards'], 'id'));
        $this->assertSame('testing', $payload['environment']);
        $this->assertSame('Connections', $payload['strings']['title']);

        $pexels = $payload['groups'][1]['cards'][1];
        $this->assertSame('not_set', $pexels['status']['state']);
        $this->assertSame('https://www.pexels.com/api/', $pexels['key_url']);
        $this->assertCount(3, $pexels['steps']);
        $this->assertSame('no_key', $payload['groups'][1]['cards'][3]['status']['state']);
        $this->assertSame(['needs_key' => true], $payload['groups'][2]['cards'][0]['oauth_links']);
    }

    public function test_only_people_who_manage_the_settings_may_open_it(): void
    {
        $this->signIn();

        $this->get(cp_route('ghostwriter.connections.show'))->assertForbidden();
        $this->postJson(cp_route('ghostwriter.connections.save', 'pexels'), ['fields' => ['key' => self::KEY]])->assertForbidden();
        $this->postJson(cp_route('ghostwriter.connections.disconnect', 'pexels'))->assertForbidden();
        $this->assertNull(app(Connections::class)->key('pexels'));
    }

    public function test_a_key_is_checked_kept_encrypted_shown_masked_and_used(): void
    {
        $this->signInAsManager();

        $response = $this->postJson(cp_route('ghostwriter.connections.save', 'pexels'), ['fields' => ['key' => '  '.self::KEY.' ']])->assertOk();

        $response->assertJsonPath('message', 'Pexels is connected.');
        $response->assertJsonPath('card.status.state', 'connected');
        $response->assertJsonPath('card.status.label', 'Connected · key ending ••wxyz');
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertSame(['pexels'], $this->check->checked);

        $file = (string) file_get_contents(EncryptedCredentialStore::path());
        $this->assertStringNotContainsString(self::KEY, $file, 'Encrypted at rest.');

        // Used wherever a key is read, with nothing in .env.
        $this->assertSame(self::KEY, app(Connections::class)->key('pexels'));
        $this->assertContains('pexels', app(StockSearch::class)->sources());
    }

    public function test_a_refused_key_is_said_plainly_and_not_kept(): void
    {
        $this->signInAsManager();

        $this->postJson(cp_route('ghostwriter.connections.save', 'pexels'), ['fields' => ['key' => 'a-wrong-key-0123456']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pexels didn’t accept that key. Check you copied all of it, with nothing before or after.');

        $this->postJson(cp_route('ghostwriter.connections.save', 'shutterstock'), ['fields' => ['key' => 'consumer-key-0123']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Paste the Secret first.');

        $this->assertNull(app(Connections::class)->key('pexels'));
    }

    public function test_a_key_in_env_wins_and_cannot_be_replaced_here(): void
    {
        config(['ghostwriter.images.pexels_key' => 'pexels-from-config-0123']);
        $this->signInAsManager();

        $card = app(ConnectionsPage::class)->card(app(Connections::class)->services()->get('pexels'));
        $this->assertSame('env', $card['status']['state']);
        $this->assertSame('Set in config', $card['status']['label']);
        $this->assertNull($card['status']['ending']);

        $this->postJson(cp_route('ghostwriter.connections.save', 'pexels'), ['fields' => ['key' => self::KEY]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pexels is set in .env (PEXELS_API_KEY), which wins. Change it there, or take it out of .env to set it up here.');
        $this->postJson(cp_route('ghostwriter.connections.disconnect', 'pexels'))->assertStatus(409);
        $this->assertSame([], $this->check->checked, 'Nothing is checked or kept.');
    }

    public function test_disconnect_forgets_the_key(): void
    {
        $this->signInAsManager();
        $this->postJson(cp_route('ghostwriter.connections.save', 'pexels'), ['fields' => ['key' => self::KEY]])->assertOk();

        $this->postJson(cp_route('ghostwriter.connections.disconnect', 'pexels'))
            ->assertOk()
            ->assertJsonPath('message', 'Pexels is disconnected.')
            ->assertJsonPath('card.status.state', 'not_set');

        $this->assertNull(app(Connections::class)->key('pexels'));
    }

    public function test_it_is_kept_in_the_database_once_the_table_is_there(): void
    {
        (require __DIR__.'/../../database/migrations/2026_10_07_000000_create_ghostwriter_credentials_table.php')->up();
        $this->app->forgetInstance(CredentialStore::class);
        $this->app->forgetInstance(Connections::class);
        $this->signInAsManager();

        $this->postJson(cp_route('ghostwriter.connections.save', 'pexels'), ['fields' => ['key' => self::KEY]])->assertOk();

        $raw = (string) DB::table('ghostwriter_credentials')->where('name', 'pexels')->value('value');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString(self::KEY, $raw);
        $this->assertFileDoesNotExist(EncryptedCredentialStore::path());
        $this->assertSame('database', app(ConnectionsPage::class)->payload()['stored_in']);
    }

    public function test_keys_and_tokens_kept_before_connections_are_moved_into_it(): void
    {
        (new EncryptedProviderKeys)->put('openrouter', 'sk-or-v1-connected-earlier-0123');
        (new EncryptedLibraryTokens)->put('shutterstock', new TokenSet('access-earlier-0123', refreshToken: 'refresh-earlier'));
        $this->app->forgetInstance(Connections::class);

        $connections = app(Connections::class);

        $this->assertSame('sk-or-v1-connected-earlier-0123', $connections->key('openrouter'));
        $this->assertSame('connect', $connections->status('openrouter')->via);
        $this->assertSame('access-earlier-0123', app(LibraryTokens::class)->get('shutterstock')?->accessToken);
        $this->assertFileDoesNotExist(EncryptedProviderKeys::path());
        $this->assertFileDoesNotExist(EncryptedLibraryTokens::path());
    }

    public function test_every_key_goes_through_the_watched_clients_and_the_resolver(): void
    {
        $this->app->forgetInstance(HttpClients::class);
        $this->assertInstanceOf(KeyWatch::class, app(HttpClients::class));

        config(['ghostwriter.keys.anthropic' => null, 'ai.providers.anthropic.key' => null, 'ghostwriter.provider' => 'anthropic']);
        app(Connections::class)->save('anthropic', ['key' => 'sk-ant-pasted-0123456789']);
        $this->app->forgetInstance(Providers::class);

        $this->assertTrue($this->app->make(Providers::class)->configured(), 'A key set up here writes, with nothing in .env.');
    }

    public function test_the_nav_and_get_started_lead_here(): void
    {
        $this->signInAsManager();

        $steps = collect(app(Onboarding::class)->steps())->keyBy('key');
        $this->assertSame(cp_route('ghostwriter.connections.show'), $steps['key']['action']['url']);
        $this->assertSame('Set up in Connections', $steps['key']['action']['label']);
    }
}
