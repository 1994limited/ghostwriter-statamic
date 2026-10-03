<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use NineteenNinetyFour\Ghostwriter\Jobs\FindImages;
use NineteenNinetyFour\Ghostwriter\Stock\CompStore;
use NineteenNinetyFour\Ghostwriter\Stock\DemoLibrary;
use NineteenNinetyFour\Ghostwriter\Stock\Ledger;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Publish safety (design §7.1, §7.3, §7.4): a page holding an unlicensed
 * preview can't be published (or, set to warn, is published with a
 * warning); the Overview tile and the Stock images screen with its CSV;
 * and the cleanup of expired comps, unused previews and licences in doubt.
 */
class StockPublishTest extends TestCase
{
    private FakeLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ghostwriter.stock.demo' => true,
            'ghostwriter.images.openverse' => false,
            'filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->workspace.'/assets', 'url' => '/assets'],
        ]);

        $this->library = DemoLibrary::make();
        $this->app->instance(DemoLibrary::BINDING, $this->library);

        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();
        Collection::make('stories')->title('Stories')->save();
        Blueprint::make('story')->setNamespace('collections.stories')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'cover', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => 'Hero image', 'folder' => 'covers']],
        ]])->save();
        Entry::make()->id('one')->collection('stories')->slug('one')->published(false)->data(['title' => 'One'])->save();
    }

    public function test_a_page_holding_a_preview_cant_be_published_but_can_be_saved_unpublished(): void
    {
        $this->signInToLicense();
        $preview = $this->preview();

        $entry = Entry::find('one');
        $entry->set('cover', $this->path($preview))->save();
        $this->assertFalse(Entry::find('one')->published(), 'Saved as a draft: allowed.');

        try {
            $entry->published(true)->save();
            $this->fail('Publishing should have been refused.');
        } catch (ValidationException $refused) {
            $this->assertSame(['cover' => ['This is a Demo preview, not licensed yet. License it, or choose another image, before publishing.']], $refused->errors());
        }

        // Once licensed, it goes.
        $this->postJson(cp_route('ghostwriter.stock.license', $preview['id']), ['option' => 'demo-pack'])->assertOk();
        Entry::find('one')->published(true)->save();
        $this->assertTrue(Entry::find('one')->published());
    }

    public function test_the_publish_forms_save_shows_the_message_on_the_image_field(): void
    {
        $this->signInToLicense();
        $preview = $this->preview();
        $entry = Entry::find('one');
        $entry->set('cover', $this->path($preview))->save();

        $this->patchJson($entry->updateUrl(), ['title' => 'One', 'cover' => [$preview['asset']], 'published' => true, 'blueprint' => 'story', '_localized' => []])
            ->assertStatus(422)
            ->assertJsonPath('errors.cover.0', 'This is a Demo preview, not licensed yet. License it, or choose another image, before publishing.');
    }

    public function test_set_to_warn_a_page_is_published_with_a_warning(): void
    {
        // The stock photos setting this replaced still counts when it isn't set.
        config(['ghostwriter.stock.on_publish' => 'warn']);
        $this->signIn();
        $preview = $this->preview();

        Entry::find('one')->set('cover', $this->path($preview))->published(true)->save();

        $this->assertTrue(Entry::find('one')->published());
    }

    public function test_the_overview_tile_and_the_stock_images_screen_list_the_previews(): void
    {
        $this->signInToLicense();
        $preview = $this->preview();
        Entry::find('one')->set('cover', $this->path($preview))->save();
        $this->preview('demo-102');

        $overview = app(Ledger::class)->overview();
        $this->assertSame(2, $overview['previews']);
        $this->assertFalse($overview['warning'], 'Used only on a draft, with its comp held.');

        $page = $this->get(cp_route('ghostwriter.stock.index'))->assertOk();
        $props = $page->viewData('page')['props'];
        $this->assertSame('previews', $props['tab']);
        $this->assertSame(['previews' => 2, 'licensed' => 0, 'failed' => 0, 'all' => 2], collect($props['tabs'])->pluck('count', 'key')->all());
        $this->assertCount(2, $props['records']);

        // Requested licences come first.
        $this->postJson(cp_route('ghostwriter.stock.request', $preview['id']))->assertOk();
        $this->assertSame($preview['id'], $this->get(cp_route('ghostwriter.stock.index'))->viewData('page')['props']['records'][0]['id']);

        // A preview in use can't be removed; one unused can.
        $this->postJson(cp_route('ghostwriter.stock.remove', $preview['id']))->assertStatus(422)->assertJsonPath('message', 'A page still uses this preview. Take it out of the page first, then remove it.');

        $this->postJson(cp_route('ghostwriter.stock.license', $preview['id']), ['option' => 'demo-pack'])->assertOk();
        $csv = $this->get(cp_route('ghostwriter.stock.csv', ['tab' => 'all']))->assertOk()->streamedContent();
        $this->assertStringStartsWith('Date,Library,ID,Title,State,"Order ID",Cost,"Licence type","Licensed by"', $csv);
        $this->assertStringContainsString('demo,demo-101,"Stone path through a summer meadow",licensed,demo-order-1,"1 download",royalty_free', $csv);
        $this->assertStringContainsString('One (Hero image)', $csv);

        $this->get(cp_route('ghostwriter.stock.record', $preview['id']))->assertOk()->assertJsonPath('licence.order_id', 'demo-order-1');
    }

    public function test_cleanup_deletes_expired_comps_and_unused_previews_and_settles_licences_in_doubt(): void
    {
        $this->signInToLicense();
        $used = $this->preview('demo-101');
        $unused = $this->preview('demo-102');
        $doubt = $this->preview('demo-103');

        // The entry keeps the first; the others aren't on any page any more.
        Entry::find('one')->set('cover', $this->path($used))->save();

        $this->library->licenceOutcomes(FakeLibrary::UNCERTAIN_CHARGED);
        $this->postJson(cp_route('ghostwriter.stock.license', $doubt['id']), ['option' => 'demo-pack'])->assertStatus(422)->assertJsonPath('stock.state', 'licensing');

        // Thirty-one days on.
        Carbon::setTestNow(Carbon::now()->addDays(31));
        $comp = app(StockImageStore::class)->find($used['id'])->comp();

        $this->artisan('ghostwriter:stock-cleanup')->expectsOutputToContain('3 expired comps deleted, 1 unused preview removed, 1 licence settled.')->assertSuccessful();

        $kept = app(StockImageStore::class)->find($used['id']);
        $this->assertSame(StockImage::PREVIEW, $kept->state(), 'Still in use: the stand-in stays.');
        $this->assertNull($kept->comp());
        $this->assertFileDoesNotExist(app(CompStore::class)->directory().'/'.$comp);
        $this->assertNotNull(AssetContainer::find('assets')->asset($this->path($used)));
        $this->getJson(cp_route('ghostwriter.stock.show', $used['id']))->assertJsonPath('comp_expired', true);

        $this->assertSame(StockImage::REMOVED, app(StockImageStore::class)->find($unused['id'])->state());
        $this->assertNull(AssetContainer::find('assets')->asset($this->path($unused)));

        $settled = app(StockImageStore::class)->find($doubt['id']);
        $this->assertSame(StockImage::LICENSED, $settled->state());
        $this->assertSame(1, $this->library->licenceCalls('demo-103'), 'Settled from the library\'s licences, never bought again.');

        $this->assertTrue(app(Ledger::class)->overview()['warning'], 'A preview whose comp expired needs attention.');
    }

    private function signInToLicense(): void
    {
        config(['statamic.editions.pro' => true]);
        $this->signInWith(['access ghostwriter', 'license stock images', 'publish stories entries', ...self::WRITER_PERMISSIONS]);
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(string $photo = 'demo-101'): array
    {
        Bus::fake([FindImages::class]);
        $started = $this->postJson(cp_route('ghostwriter.images.start'), ['collection' => 'stories', 'path' => 'cover', 'entry' => 'one', 'title' => 'One', 'mode' => 'find', 'words' => 'path', 'source' => 'demo'])->json();
        $this->runJob(new FindImages($started['id']));

        return $this->postJson(cp_route('ghostwriter.images.use', $started['id']), ['source' => 'demo', 'photo' => $photo, 'current' => []])->assertOk()->json('stock');
    }

    private function path(array $preview): string
    {
        return explode('::', $preview['asset'], 2)[1];
    }
}
