<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\HistoryEvent;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\InsufficientBalance;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use NineteenNinetyFour\Ghostwriter\Http\Middleware\StockPreviewsInLivePreview;
use NineteenNinetyFour\Ghostwriter\Jobs\FindImages;
use NineteenNinetyFour\Ghostwriter\Stock\CompStore;
use NineteenNinetyFour\Ghostwriter\Stock\DemoLibrary;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Contracts\Assets\Asset;
use Statamic\Events\AssetContainerBlueprintFound;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Tokens\Handlers\LivePreview;

/**
 * License & replace (design §6, §8.3) with the demo library: the confirm
 * step, the licence bought once, the stand-in's file swapped for the
 * licensed one with its alt text, title and focal point kept, failures in
 * plain words, the permission, Request licence and Refresh preview; the
 * asset editor's panel; and Live Preview showing the comp.
 */
class StockLicensingTest extends TestCase
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

        // One scripted demo library for the whole test, so licences and
        // outcomes carry over between requests.
        $this->library = DemoLibrary::make();
        $this->app->instance(DemoLibrary::BINDING, $this->library);

        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();
        Blueprint::make('assets')->setNamespace('assets')->setContents(['fields' => [['handle' => 'alt', 'field' => ['type' => 'text']]]])->save();
        Collection::make('stories')->title('Stories')->save();
        Blueprint::make('story')->setNamespace('collections.stories')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'cover', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => 'Cover', 'folder' => 'covers']],
        ]])->save();
        Entry::make()->id('one')->collection('stories')->slug('one')->published(false)->data(['title' => 'One'])->save();
    }

    public function test_the_confirm_step_shows_the_options_cost_and_credit(): void
    {
        $this->signInToLicense();
        $preview = $this->preview();

        $this->getJson(cp_route('ghostwriter.stock.quotes', $preview['id']))
            ->assertOk()
            ->assertJsonPath('options.0.name', 'Standard licence, 2,400 px')
            ->assertJsonPath('options.0.cost', 'Uses 1 of your 100 remaining downloads (Demo pack).')
            ->assertJsonPath('options.1.cost', 'Uses 3 of your 100 remaining downloads (Demo pack).')
            ->assertJsonPath('credit_line', 'Demo photographer/Demo stock (no charge)')
            ->assertJsonPath('credit_note', 'If this page is news, a blog post or other editorial use, show this credit next to the image.');
    }

    public function test_license_and_replace_swaps_the_file_and_keeps_alt_title_and_focus(): void
    {
        $this->signInToLicense();
        $preview = $this->preview();
        $asset = $this->asset($preview);
        $asset->set('alt', 'A path the editor described')->set('focus', '30-70-1')->save();
        $comp = app(StockImageStore::class)->find($preview['id'])->comp();

        $this->postJson(cp_route('ghostwriter.stock.license', $preview['id']), ['option' => 'demo-pack'])
            ->assertOk()
            ->assertJsonPath('message', 'Licensed. The preview has been replaced with the full image.')
            ->assertJsonPath('stock.state', 'licensed')
            ->assertJsonPath('stock.unlicensed', false);

        $this->assertSame(1, $this->library->licenceCalls('demo-101'), 'Bought exactly once.');

        $record = app(StockImageStore::class)->find($preview['id']);
        $this->assertSame(StockImage::LICENSED, $record->state());
        $this->assertTrue($record->isReplaced());
        $this->assertSame('demo-order-1', $record->licence()->orderId);
        $this->assertNull($record->comp());
        $this->assertFileDoesNotExist(app(CompStore::class)->directory().'/'.$comp, 'The comp goes once licensed.');
        $this->assertContains(HistoryEvent::REPLACED, array_map(fn ($event) => $event->event, $record->history()));

        // Same asset, same path: the licensed file now, its alt, title and focus as they were.
        $asset = $this->asset($preview);
        $size = getimagesizefromstring((string) Storage::disk('assets')->get($asset->path()));
        $this->assertSame([800, 534], [$size[0], $size[1]], 'The licensed file, not the 1600px stand-in.');
        $this->assertSame('A path the editor described', $asset->get('alt'));
        $this->assertSame('Stone path through a summer meadow', $asset->get('title'));
        $this->assertSame('30-70-1', $asset->get('focus'));
        $this->assertSame('Demo photographer/Demo stock (no charge)', $asset->get('credit'));

        // Never twice.
        $this->postJson(cp_route('ghostwriter.stock.license', $preview['id']), ['option' => 'demo-pack'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This image is already licensed.');
        $this->assertSame(1, $this->library->licenceCalls('demo-101'));
    }

    public function test_failures_are_told_in_plain_words_and_nothing_is_bought_twice(): void
    {
        $this->signInToLicense();
        $preview = $this->preview();

        $this->library->licenceOutcomes(new InsufficientBalance('Your demo account has no downloads left.'), FakeLibrary::UNCERTAIN_NOT_CHARGED);

        $this->postJson(cp_route('ghostwriter.stock.license', $preview['id']), ['option' => 'demo-pack'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your demo account has no downloads left.')
            ->assertJsonPath('stock.state', 'failed');

        // Tried again: the outcome is unknown, so it stays licensing and says don't buy it again.
        $this->postJson(cp_route('ghostwriter.stock.license', $preview['id']), ['option' => 'demo-pack'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'We couldn\'t confirm the purchase. Ghostwriter will check with Demo stock (no charge) in a few minutes; don\'t buy it again.')
            ->assertJsonPath('stock.state', 'licensing');

        $this->postJson(cp_route('ghostwriter.stock.license', $preview['id']), ['option' => 'demo-pack'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This image is already being licensed. Ghostwriter will check how that went; don\'t buy it again.');

        $this->assertSame(2, $this->library->licenceCalls('demo-101'));
    }

    public function test_a_file_that_cant_go_in_place_keeps_the_licence_for_download_again(): void
    {
        $this->signInToLicense();
        $preview = $this->preview();
        $asset = $this->asset($preview);

        // The stand-in is a JPEG; a library sending a PNG isn't converted.
        Storage::disk('assets')->move($asset->path(), $png = str_replace('.jpg', '.png', $asset->path()));
        $record = app(StockImageStore::class)->find($preview['id']);
        $record->asset = AssetRef::statamic('assets', $png);
        app(StockImageStore::class)->save($record);
        AssetContainer::find('assets')->makeAsset($png)->save();

        $this->postJson(cp_route('ghostwriter.stock.license', $preview['id']), ['option' => 'demo-pack'])
            ->assertStatus(422)
            ->assertJsonPath('stock.state', 'licensed')
            ->assertJsonPath('stock.replaced', false);
        $this->assertStringContainsString('Demo stock (no charge) sent a jpg file; replace the image by hand.', app(StockImageStore::class)->find($preview['id'])->error());
        $this->assertSame(1, $this->library->licenceCalls('demo-101'));
    }

    public function test_without_the_permission_editors_can_only_request_a_licence(): void
    {
        $this->signIn();
        $preview = $this->preview();
        $this->assertFalse($preview['can_license']);

        $this->getJson(cp_route('ghostwriter.stock.quotes', $preview['id']))->assertForbidden();
        $this->postJson(cp_route('ghostwriter.stock.license', $preview['id']), ['option' => 'demo-pack'])->assertForbidden();
        $this->assertSame(0, $this->library->licenceCalls('demo-101'));

        $this->postJson(cp_route('ghostwriter.stock.request', $preview['id']))
            ->assertOk()
            ->assertJsonPath('stock.requested.by', 'writer@example.com')
            ->assertJsonPath('stock.state', 'preview');

        $record = app(StockImageStore::class)->find($preview['id']);
        $this->assertSame('licence_requested', last($record->history())->event);
    }

    public function test_an_expired_preview_can_be_refreshed_once(): void
    {
        $this->signIn();
        $preview = $this->preview();
        $record = app(StockImageStore::class)->find($preview['id']);
        app(CompStore::class)->forget($record->comp());

        $this->getJson(cp_route('ghostwriter.stock.show', $preview['id']))->assertJsonPath('comp_expired', true)->assertJsonPath('may_refresh', true);

        $this->postJson(cp_route('ghostwriter.stock.refresh', $preview['id']))->assertOk()->assertJsonPath('stock.comp_expired', false);
        app(CompStore::class)->forget(app(StockImageStore::class)->find($preview['id'])->comp());

        $this->getJson(cp_route('ghostwriter.stock.show', $preview['id']))->assertJsonPath('may_refresh', false);
        $this->postJson(cp_route('ghostwriter.stock.refresh', $preview['id']))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This preview has been refreshed once already. License it or remove it.');
    }

    public function test_the_asset_editor_shows_the_stock_photo_panel_and_the_form_finds_its_previews(): void
    {
        $this->signIn();
        $preview = $this->preview();
        $asset = $this->asset($preview);

        $blueprint = AssetContainer::find('assets')->blueprint();
        AssetContainerBlueprintFound::dispatch($blueprint, AssetContainer::find('assets'), $asset);
        $this->assertSame('ghostwriter_stock', $blueprint->field('ghostwriter_stock')?->type());
        $this->assertSame($preview['id'], $blueprint->field('ghostwriter_stock')->setValue($preview['id'])->preProcess()->value());

        $meta = $blueprint->field('ghostwriter_stock')->setValue($preview['id'])->meta();
        $this->assertSame('preview', $meta['record']['state']);

        $found = $this->postJson(cp_route('ghostwriter.stock.assets'), ['assets' => ['assets::'.$asset->path(), 'assets::nothing.jpg']])->assertOk()->json('assets');
        $this->assertSame(['assets::'.$asset->path()], array_keys($found));
        $this->assertSame($preview['id'], $found['assets::'.$asset->path()]['id']);
    }

    public function test_live_preview_shows_signed_in_editors_the_comp_and_everyone_else_the_stand_in(): void
    {
        $this->signIn();
        $preview = $this->preview();
        $name = basename($this->asset($preview)->path());
        $html = "<img src=\"/assets/covers/{$name}\"><img srcset=\"/img/asset/YXNzZXRz/{$name}?w=400&amp;s=x 400w, /img/asset/YXNzZXRz/{$name}?w=800 800w\"><img src=\"/assets/other.jpg\">";
        $run = fn () => app(StockPreviewsInLivePreview::class)->handle(Request::create('/stories/one'), fn () => response($html, 200, ['Content-Type' => 'text/html']));

        $this->assertSame($html, $run()->getContent(), 'Not a Live Preview request: untouched.');

        Request::macro('isLivePreview', fn () => true);
        $comp = cp_route('ghostwriter.stock.comp', $preview['id']);
        $response = $run();

        $this->assertStringContainsString("<img src=\"{$comp}\">", $response->getContent());
        $this->assertStringContainsString("srcset=\"{$comp} 400w, {$comp} 800w\"", $response->getContent());
        $this->assertStringContainsString('/assets/other.jpg', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        auth()->logout();
        $this->assertSame($html, $run()->getContent(), 'Signed out (a shared preview link): the stand-in.');

        Request::macro('isLivePreview', fn () => optional($this->statamicToken())->handler() === LivePreview::class);
    }

    /**
     * Someone who may license stock images (it spends from the account).
     */
    private function signInToLicense(): void
    {
        config(['statamic.editions.pro' => true]);
        $this->signInWith(['access ghostwriter', 'license stock images', ...self::WRITER_PERMISSIONS]);
    }

    /**
     * A demo photo put into the cover as a preview, as the dialog does it.
     *
     * @return array<string, mixed>
     */
    private function preview(string $photo = 'demo-101'): array
    {
        Bus::fake([FindImages::class]);
        $started = $this->postJson(cp_route('ghostwriter.images.start'), ['collection' => 'stories', 'path' => 'cover', 'entry' => 'one', 'title' => 'One', 'mode' => 'find', 'words' => 'path', 'source' => 'demo'])->json();
        $this->runJob(new FindImages($started['id']));

        return $this->postJson(cp_route('ghostwriter.images.use', $started['id']), ['source' => 'demo', 'photo' => $photo, 'current' => []])->assertOk()->json('stock');
    }

    private function asset(array $preview): Asset
    {
        return AssetContainer::find('assets')->asset(explode('::', $preview['asset'], 2)[1]);
    }
}
