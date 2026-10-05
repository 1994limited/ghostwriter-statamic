<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\Ghostwriter\Connections\EncryptedCredentialStore;
use NineteenNinetyFour\Ghostwriter\Core\Connections\StoredLibraryTokens;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Ports\LibraryTokens;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use NineteenNinetyFour\Ghostwriter\Jobs\FindImages;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Stock\DemoLibrary;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Connect account / Disconnect for a library that licenses only for a
 * signed-in account, against core's FakeLibrary with needsOAuth (it plays
 * the provider: its sign-in sends the browser straight back with a code
 * bound to the state and the callback address). Core's
 * docs/connecting-accounts.md: single-use state checked before connect(),
 * one callback address, tokens encrypted, managers only.
 */
class StockConnectTest extends TestCase
{
    private FakeLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ghostwriter.stock.demo' => true, 'ghostwriter.images.openverse' => false, 'app.url' => 'https://cms.example.com']);

        $this->library = DemoLibrary::make();
        $this->library = new FakeLibrary(
            capabilities: Capabilities::paid(Capabilities::QUOTES_BALANCE, 30, needsOAuth: true, termsCheckedAt: '2026-10-02', editorial: true),
            tokens: app(LibraryTokens::class),
        );
        $this->library->withPhotos('demo-101');
        $this->app->instance(DemoLibrary::BINDING, $this->library);
    }

    public function test_the_tokens_are_kept_encrypted(): void
    {
        $this->assertInstanceOf(StoredLibraryTokens::class, app(LibraryTokens::class));
    }

    public function test_connect_account_signs_in_and_keeps_the_tokens_encrypted(): void
    {
        $this->signInAsManager();

        $connect = $this->get(cp_route('ghostwriter.libraries.connect', 'demo'))->assertRedirect();
        $location = (string) $connect->headers->get('Location');
        $this->assertStringStartsWith('https://cms.example.com/cp/ghostwriter/libraries/demo/callback?code=', $location, 'The callback is absolute, from the site\'s own URL.');

        $this->get($location)->assertRedirect(cp_route('ghostwriter.connections.show'));
        $this->assertTrue($this->library->connected());

        $file = (string) file_get_contents(EncryptedCredentialStore::path());
        $token = app(LibraryTokens::class)->get('demo')->accessToken;
        $this->assertStringNotContainsString($token, $file, 'Encrypted at rest.');

        // The state was single use.
        $this->get($location)->assertForbidden();

        // The settings row says so, and offers Disconnect.
        $rows = collect(app(StockLibraries::class)->rows())->keyBy('id');
        $this->assertTrue($rows['demo']['connect']['connected']);

        $this->postJson(cp_route('ghostwriter.libraries.disconnect', 'demo'))->assertOk()->assertJsonPath('message', 'Demo stock (no charge) is disconnected.');
        $this->assertFalse($this->library->connected());
        $this->assertNull(app(LibraryTokens::class)->get('demo'));
    }

    public function test_a_callback_with_a_wrong_or_missing_state_is_refused_and_nothing_is_kept(): void
    {
        $this->signInAsManager();
        $location = (string) $this->get(cp_route('ghostwriter.libraries.connect', 'demo'))->headers->get('Location');
        $forged = preg_replace('/state=[^&]+/', 'state=forged', $location);

        $this->get($forged)->assertForbidden();
        $this->assertFalse($this->library->connected());

        // Nor without starting from Connect account at all.
        $this->get($location)->assertForbidden();
        $this->assertFalse($this->library->connected());
    }

    public function test_a_refused_sign_in_says_so_and_connects_nothing(): void
    {
        $this->signInAsManager();
        $this->library->refusesConnect = true;
        $location = (string) $this->get(cp_route('ghostwriter.libraries.connect', 'demo'))->headers->get('Location');

        $this->get($location)->assertRedirect();
        $this->assertFalse($this->library->connected());
        $this->assertStringContainsString('didn\'t accept the sign-in', json_encode(session()->all()));

        $location = (string) $this->get(cp_route('ghostwriter.libraries.connect', 'demo'))->headers->get('Location');
        $this->get($location.'&error=access_denied')->assertRedirect();
        $this->assertFalse($this->library->connected());
    }

    public function test_only_managers_may_connect_or_disconnect(): void
    {
        $this->signIn();

        $this->get(cp_route('ghostwriter.libraries.connect', 'demo'))->assertForbidden();
        $this->get(cp_route('ghostwriter.libraries.callback', 'demo').'?code=x&state=y')->assertForbidden();
        $this->postJson(cp_route('ghostwriter.libraries.disconnect', 'demo'))->assertForbidden();
    }

    public function test_licensing_without_a_connection_asks_to_connect_again(): void
    {
        config([
            'statamic.editions.pro' => true,
            'filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->workspace.'/assets', 'url' => '/assets'],
        ]);
        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();
        Collection::make('stories')->title('Stories')->save();
        Blueprint::make('story')->setNamespace('collections.stories')->setContents(['fields' => [['handle' => 'cover', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1]]]])->save();
        Entry::make()->id('one')->collection('stories')->slug('one')->data(['title' => 'One'])->save();
        $this->signInWith(['access ghostwriter', 'license stock images', 'edit 1994/ghostwriter-statamic settings', ...self::WRITER_PERMISSIONS]);

        // Searching and inserting a preview need no connection.
        Bus::fake([FindImages::class]);
        $started = $this->postJson(cp_route('ghostwriter.images.start'), ['collection' => 'stories', 'path' => 'cover', 'entry' => 'one', 'mode' => 'find', 'words' => 'rocks', 'source' => 'demo'])->json();
        $this->runJob(new FindImages($started['id']));
        $id = $this->postJson(cp_route('ghostwriter.images.use', $started['id']), ['source' => 'demo', 'photo' => 'demo-101', 'current' => []])->assertOk()->json('stock.id');

        $this->getJson(cp_route('ghostwriter.stock.quotes', $id))
            ->assertStatus(422)
            ->assertJsonPath('connect_url', app(Settings::class)->url());

        $this->postJson(cp_route('ghostwriter.stock.license', $id), ['option' => 'demo-pack'])
            ->assertStatus(422)
            ->assertJsonPath('stock.state', 'preview')
            ->assertJsonPath('connect_url', app(Settings::class)->url());
        $this->assertSame(0, count($this->library->bought('demo-101')));
    }
}
