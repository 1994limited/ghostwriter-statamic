<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Stock\DemoLibrary;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Addon;
use Statamic\Facades\Permission;

/**
 * The photo libraries a site can search, built from config: the free
 * ones, paid ones only when set up, and the demo library only where it is
 * allowed; and their rows on the settings screen.
 */
class StockLibrariesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ghostwriter.images.openverse' => true]);
    }

    public function test_the_demo_library_is_only_on_local_or_test_sites_and_never_in_production(): void
    {
        $this->assertFalse(StockLibraries::demoAllowed(), 'Not on a testing site by default.');

        config(['ghostwriter.stock.demo' => true]);
        $this->assertTrue(StockLibraries::demoAllowed(), 'Turned on in config.');

        $this->app['env'] = 'production';
        $this->assertFalse(StockLibraries::demoAllowed(), 'Never in production, whatever config says.');

        $this->app['env'] = 'local';
        config(['ghostwriter.stock.demo' => null]);
        $this->assertTrue(StockLibraries::demoAllowed(), 'On by itself on a local site.');

        config(['ghostwriter.stock.demo' => false]);
        $this->assertFalse(StockLibraries::demoAllowed(), 'Unless config turns it off.');
    }

    public function test_paid_libraries_are_built_only_when_set_up_and_switched_on(): void
    {
        $libraries = app(StockLibraries::class);
        $this->assertSame([], $libraries->configured(), 'No demo, and no paid library has an adapter and keys.');

        // Shutterstock's keys alone don't make it: core has no adapter for it yet.
        config(['ghostwriter.stock.keys.shutterstock' => 'sk-key', 'ghostwriter.stock.keys.shutterstock_secret' => 'sk-secret', 'ghostwriter.stock.demo' => true]);
        $libraries = app(StockLibraries::class);

        $this->assertSame(['demo'], array_keys($libraries->configured()));
        $this->assertInstanceOf(FakeLibrary::class, $libraries->licensable('demo'));
        $this->assertSame('Demo stock (no charge)', $libraries->label('demo'));
        $this->assertSame('Demo', $libraries->shortLabel('demo'));
        $this->assertSame(['free', 'demo', 'everything'], array_column($libraries->choices(), 'value'));

        Addon::get(Settings::ADDON)->settings()->set(['stock_demo' => false])->save();
        $libraries = app(StockLibraries::class);

        $this->assertNull($libraries->licensable('demo'), 'Switched off on the settings screen.');
        $this->assertSame(['free'], array_column($libraries->choices(), 'value'));
    }

    public function test_search_in_covers_the_chosen_libraries_and_leaves_editorial_out_unless_asked(): void
    {
        config(['ghostwriter.stock.demo' => true]);
        $libraries = app(StockLibraries::class);

        $demo = $libraries->search('demo')->search('garden');
        $this->assertCount(8, $demo, 'The demo library alone: a page of nine, less the editorial one.');
        $this->assertSame([], array_values(array_filter($demo, fn ($photo) => $photo->editorial)));
        $this->assertSame(['demo'], array_values(array_unique(array_map(fn ($photo) => $photo->source, $demo))));

        $editorial = $libraries->search('demo', editorial: true)->search('garden');
        $this->assertContains('demo-109', array_map(fn ($photo) => $photo->id, $editorial));

        $this->assertSame(['openverse'], $libraries->search('free')->sources());
        $this->assertSame(['openverse', 'demo'], $libraries->search('everything')->sources());
        $this->assertSame([], $libraries->search('getty')->sources(), 'Nothing for a library that is not on offer.');
    }

    public function test_search_in_starts_where_the_person_left_it_then_on_the_sites_default(): void
    {
        config(['ghostwriter.stock.demo' => true]);
        $user = $this->signIn();
        $libraries = app(StockLibraries::class);

        $this->assertSame('free', $libraries->startingSource());

        Addon::get(Settings::ADDON)->settings()->set(['stock_default_source' => 'everything'])->save();
        $this->assertSame('everything', $libraries->startingSource());

        $libraries->remember('demo');
        $this->assertSame('demo', $user->fresh()->getPreference(StockLibraries::PREFERENCE));
        $this->assertSame('demo', $libraries->startingSource($user->fresh()));
    }

    public function test_the_settings_screen_lists_each_paid_library_with_its_key_status_but_never_a_key(): void
    {
        config(['ghostwriter.stock.demo' => true, 'ghostwriter.stock.keys.shutterstock' => 'sk-live-not-shown', 'ghostwriter.stock.on_publish' => 'warn']);

        $fields = collect(Addon::get(Settings::ADDON)->settingsBlueprint()->fields()->all());
        $html = $fields['stock_libraries']->get('html');

        $this->assertStringContainsString('Demo stock (no charge)', $html);
        $this->assertStringContainsString('data-ghostwriter-check-connection="demo"', $html);
        $this->assertStringContainsString('GETTY_API_KEY', $html);
        $this->assertStringContainsString('SHUTTERSTOCK_API_KEY</code> <span', $html);
        $this->assertStringContainsString('SHUTTERSTOCK_API_SECRET', $html);
        $this->assertStringContainsString('Coming: a later version of Ghostwriter adds this library.', $html);
        $this->assertStringNotContainsString('sk-live-not-shown', $html);
        $this->assertStringNotContainsString('data-ghostwriter-check-connection="shutterstock"', $html, 'Inert until core has its adapter.');

        $this->assertTrue($fields->has('stock_demo'), 'A switch for the demo library.');
        $this->assertFalse($fields->has('stock_shutterstock'));
        $this->assertSame(['free' => 'Free libraries', 'demo' => 'Demo stock (no charge)', 'everything' => 'Everything'], $fields['stock_default_source']->get('options'));
        $this->assertSame('read_only', $fields['stock_on_publish']->get('visibility'), 'Config wins.');
        $this->assertSame(Settings::WARN, app(Settings::class)->stockOnPublish());
    }

    public function test_check_connection_says_what_the_account_can_buy_to_managers_only(): void
    {
        config(['ghostwriter.stock.demo' => true]);

        $this->signIn();
        $this->postJson(cp_route('ghostwriter.stock.check', 'demo'))->assertForbidden();

        $this->signInAsManager();
        $this->postJson(cp_route('ghostwriter.stock.check', 'demo'))
            ->assertOk()
            ->assertJson(['ok' => true, 'account' => 'Demo account', 'products' => ['Demo pack: 100 downloads left']]);

        $this->postJson(cp_route('ghostwriter.stock.check', 'shutterstock'))->assertOk()->assertJson(['ok' => false]);
    }

    public function test_the_demo_librarys_thumbnails_are_drawn_for_the_control_panel_only(): void
    {
        config(['ghostwriter.stock.demo' => true]);

        $this->get(cp_route('ghostwriter.stock.demo.thumb', 'demo-101'))->assertRedirect();

        $this->signIn();
        $response = $this->get(cp_route('ghostwriter.stock.demo.thumb', 'demo-101'))->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->get(cp_route('ghostwriter.stock.demo.thumb', 'nope'))->assertNotFound();
        $this->assertSame(count(DemoLibrary::PHOTOS), 10);
    }

    public function test_licensing_is_a_permission_of_its_own(): void
    {
        $this->assertNotNull(Permission::get('license stock images'));
    }
}
