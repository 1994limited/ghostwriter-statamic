<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;
use NineteenNinetyFour\Ghostwriter\Jobs\FindImages;
use NineteenNinetyFour\Ghostwriter\Stock\Ledger;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * The image dialog with a paid library: "Search in", the cards' source and
 * cost, the editorial filter, and "Insert preview": a stand-in asset in
 * the field, the comp kept privately, and a ledger record in `preview`.
 * All against the demo library (core's FakeLibrary): nothing is charged.
 */
class StockPreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ghostwriter.stock.demo' => true,
            'ghostwriter.images.openverse' => false,
            'filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->workspace.'/assets', 'url' => '/assets'],
        ]);

        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();
        Collection::make('stories')->title('Stories')->save();
        Blueprint::make('story')->setNamespace('collections.stories')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'cover', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => 'Cover', 'folder' => 'covers']],
        ]])->save();
        Entry::make()->id('one')->collection('stories')->slug('one')->published(false)->data(['title' => 'One'])->save();
    }

    public function test_search_in_offers_the_demo_library_and_remembers_the_choice(): void
    {
        Bus::fake([FindImages::class]);
        $user = $this->signIn();

        $tools = $this->getJson(cp_route('ghostwriter.images.tools'))->assertOk()->json();
        $this->assertTrue($tools['find']);
        $this->assertSame(['demo'], array_column($tools['sources'], 'value'), 'Openverse is off and no key is set: the demo library alone.');
        $this->assertSame('demo', $tools['source']);
        $this->assertFalse($tools['editorial']);

        $this->postJson(cp_route('ghostwriter.images.start'), $this->slot() + ['mode' => 'find', 'words' => 'meadow', 'source' => 'demo', 'editorial' => '0'])->assertOk();
        $this->assertSame('demo', $user->fresh()->getPreference(StockLibraries::PREFERENCE));

        $this->postJson(cp_route('ghostwriter.images.start'), $this->slot() + ['mode' => 'find', 'words' => 'meadow', 'source' => 'getty'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That photo library isn\'t available. Choose another in "Search in".');
    }

    public function test_paid_results_carry_their_source_and_cost_are_never_judged_and_editorial_is_left_out(): void
    {
        $this->signIn();

        $found = $this->find(editorial: false);

        $this->assertSame('done', $found['status']);
        $this->assertNotContains('demo-109', array_column($found['options'], 'id'), 'Editorial is off.');
        $option = $found['options'][0];
        $this->assertSame(['Demo', true, '1 download', false], [$option['source_label'], $option['paid'], $option['offer']['label'], $option['picked']]);
        $this->assertFalse($found['judged']);
        $this->assertSame(['Demo'], $found['paid_libraries']);
        $this->ai->assertNotSent('photo-picker');

        $withEditorial = $this->find(editorial: true);
        $editorial = collect($withEditorial['options'])->firstWhere('id', 'demo-109');
        $this->assertTrue($editorial['editorial']);
        $this->assertStringStartsWith('Editorial use only', $editorial['restrictions']);
    }

    public function test_insert_preview_puts_a_stand_in_in_the_field_keeps_the_comp_privately_and_records_a_preview(): void
    {
        $this->signIn();
        $found = $this->find();

        $kept = $this->postJson(cp_route('ghostwriter.images.use', $found['id']), ['source' => 'demo', 'photo' => 'demo-101', 'term' => 'meadow', 'current' => []])->assertOk()->json();

        $this->assertSame('Preview added. Only signed-in editors see the photo; license it before publishing.', $kept['toast']);
        $this->assertStringStartsWith('covers/stone-path-through-a-summer-meadow-demo-demo-101-', $kept['asset']['path']);
        $this->assertStringEndsWith('.jpg', $kept['asset']['path']);
        $this->assertSame(['assets::'.$kept['asset']['path']], $kept['value']);

        // The stand-in: a JPEG at the photo's aspect, titled as the photo, never the comp.
        $asset = AssetContainer::find('assets')->asset($kept['asset']['path']);
        $size = getimagesizefromstring((string) $asset->contents());
        $this->assertSame([1600, 1067, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
        $this->assertSame('Stone path through a summer meadow', $asset->get('title'));
        $this->assertSame(['covers/'.basename($kept['asset']['path'])], Storage::disk('assets')->files('covers'), 'Only the stand-in is in the container.');

        $record = app(StockImageStore::class)->query(new StockImageQuery)->images[0];
        $this->assertSame(StockImage::PREVIEW, $record->state());
        $this->assertSame(['demo', 'demo-101'], [$record->library, $record->externalId]);
        $this->assertTrue($record->noModelInput);
        $this->assertSame($record->id, $asset->get(Ledger::MARKER));
        $this->assertSame([['one', 'cover']], array_map(fn ($usage) => [(string) $usage->ownerId, $usage->field], $record->usages()));
        $this->assertNotNull($record->compKeepUntil());
        $this->assertFileExists($this->workspace.'/stock/'.$record->comp(), 'The comp is in Ghostwriter\'s private storage.');
        $this->assertSame($record->id, $kept['stock']['id']);
        $this->assertSame(cp_route('ghostwriter.stock.comp', $record->id), $kept['stock']['comp_url']);
        $this->assertTrue($kept['stock']['unlicensed']);
    }

    public function test_the_comp_is_served_only_in_the_control_panel_never_cached_or_indexed(): void
    {
        $this->signIn();
        $found = $this->find();
        $id = $this->postJson(cp_route('ghostwriter.images.use', $found['id']), ['source' => 'demo', 'photo' => 'demo-102', 'current' => []])->json('stock.id');

        $comp = $this->get(cp_route('ghostwriter.stock.comp', $id))->assertOk();
        $this->assertSame('image/png', $comp->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $comp->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $comp->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $comp->headers->get('X-Robots-Tag'));

        $this->get(cp_route('ghostwriter.stock.comp', 'nope'))->assertNotFound();

        // Without Ghostwriter access: refused; signed out: sent to sign in.
        config(['statamic.editions.pro' => true]);
        $this->signInWith([]);
        $this->get(cp_route('ghostwriter.stock.comp', $id))->assertForbidden();
        auth()->logout();
        $this->get(cp_route('ghostwriter.stock.comp', $id))->assertRedirect();
    }

    /**
     * A search of the demo library, run.
     *
     * @return array<string, mixed>
     */
    private function find(bool $editorial = false): array
    {
        Bus::fake([FindImages::class]);
        $started = $this->postJson(cp_route('ghostwriter.images.start'), $this->slot() + ['mode' => 'find', 'words' => 'meadow', 'source' => 'demo', 'editorial' => $editorial ? '1' : '0'])->assertOk()->json();
        $this->runJob(new FindImages($started['id']));

        return $this->getJson(cp_route('ghostwriter.images.status', $started['id']))->assertOk()->json();
    }

    /**
     * @return array<string, string>
     */
    private function slot(): array
    {
        return ['collection' => 'stories', 'path' => 'cover', 'entry' => 'one', 'title' => 'One'];
    }
}
