<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequest as StoredRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\HistoryEvent;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Jobs\FindImages;
use NineteenNinetyFour\Ghostwriter\Stock\Ledger;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * The stock image ledger on a Statamic site: free photos recorded as they
 * are used, where each ledger image is used kept in step as entries are
 * saved, assets moved and deleted, and paid libraries' images kept out of
 * every model call.
 */
class StockLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ghostwriter.images.unsplash_key' => 'unsplash-key', 'ghostwriter.images.openverse' => false, 'filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->workspace.'/assets', 'url' => '/assets']]);

        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();
        Collection::make('stories')->title('Stories')->save();

        Blueprint::make('story')->setNamespace('collections.stories')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'cover', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => 'Cover', 'folder' => 'covers']],
            ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => ['content' => ['display' => 'Content', 'sets' => [
                'grid' => ['display' => 'Grid', 'fields' => [
                    ['handle' => 'picture', 'field' => ['type' => 'assets', 'container' => 'assets', 'display' => 'Picture']],
                ]],
            ]]]]],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'container' => 'assets']],
        ]])->save();

        foreach (['one', 'two'] as $slug) {
            Storage::disk('assets')->put("covers/{$slug}.png", $this->png());

            Entry::make()->id($slug)->collection('stories')->slug($slug)->published(true)->data([
                'title' => ucfirst($slug),
                'cover' => "covers/{$slug}.png",
            ])->save();
        }
    }

    public function test_a_free_photo_used_in_a_field_is_recorded_as_licensed_where_it_is_used(): void
    {
        $this->signIn();
        $this->photoLibrary([
            'api.unsplash.com/photos/light2' => $this->unsplash('light2', 'a white lighthouse on a cliff at dusk'),
            'api.unsplash.com/photos/light2/download' => [],
            'images.unsplash.com/*' => $this->png(),
        ]);

        $request = StoredRequest::start(Format::Statamic, StoredRequest::FIND, (string) User::current()->id(), ['slot' => ['stories', null, 'cover', null, 'one', 'One', '', '']]);
        app(ImageRequestStore::class)->save($request);

        $kept = $this->postJson(cp_route('ghostwriter.images.use', $request->id), ['source' => 'unsplash', 'photo' => 'light2', 'current' => []])->assertOk()->json();

        $records = app(StockImageStore::class)->query(new StockImageQuery)->images;
        $this->assertCount(1, $records);
        $record = $records[0];

        $this->assertSame(StockImage::LICENSED, $record->state());
        $this->assertSame(['unsplash', 'light2'], [$record->library, $record->externalId]);
        $this->assertSame('assets::'.$kept['asset']['path'], $record->asset->key());
        $this->assertSame('Ada on Unsplash', $record->creditLine);
        $this->assertFalse($record->noModelInput);
        $this->assertSame((string) User::current()->id(), (string) $record->insertedBy?->id);
        $this->assertSame([['entry', 'one', 'cover', 'Cover']], array_map(fn ($usage) => [$usage->ownerType, (string) $usage->ownerId, $usage->field, $usage->label], $record->usages()));

        // The asset carries its record's ID, so a copy can be traced.
        $this->assertSame($record->id, AssetContainer::find('assets')->asset($kept['asset']['path'])->get(Ledger::MARKER));

        // On disk: one YAML file in the stock path.
        $this->assertFileExists($this->workspace.'/content/stock/'.$record->id.'.yaml');
    }

    public function test_saving_an_entry_keeps_where_each_ledger_image_is_used_in_step(): void
    {
        // These entries are published: let the preview through, with a warning.
        config(['ghostwriter.stock.on_publish' => 'warn']);
        $paid = $this->record('covers/one.png', 'getty');
        $free = $this->record('covers/two.png', 'unsplash', free: true);

        // Used as the cover, inside a replicator set and in a Bard image node.
        $entry = Entry::find('two');
        $entry->data([
            'title' => 'Two',
            'cover' => 'covers/one.png',
            'blocks' => [['id' => 'b1', 'type' => 'grid', 'enabled' => true, 'picture' => ['covers/two.png']]],
            'body' => [['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['src' => 'asset::assets::covers/two.png']]]]],
        ])->save();

        $paid = app(StockImageStore::class)->find($paid->id);
        $free = app(StockImageStore::class)->find($free->id);

        $this->assertSame([['two', 'cover', 'Cover', true]], $this->usages($paid));
        $this->assertSame([['two', 'blocks.0.picture', 'Blocks: Picture', true], ['two', 'body', 'Body', true]], $this->usages($free));

        // Taken out of the cover: that usage goes, with a line in the history.
        $entry->set('cover', null)->save();

        $paid = app(StockImageStore::class)->find($paid->id);
        $this->assertSame([], $this->usages($paid));
        $this->assertSame(HistoryEvent::USAGE_REMOVED, last($paid->history())->event);
        $this->assertCount(2, app(StockImageStore::class)->find($free->id)->usages());
    }

    public function test_a_moved_asset_keeps_its_record_and_a_deleted_one_is_marked_removed(): void
    {
        $record = $this->record('covers/one.png', 'getty');

        $asset = AssetContainer::find('assets')->asset('covers/one.png');
        $asset->move('moved');

        $this->assertSame('assets::moved/one.png', app(StockImageStore::class)->find($record->id)->asset->key());

        AssetContainer::find('assets')->asset('moved/one.png')->delete();

        $gone = app(StockImageStore::class)->find($record->id);
        $this->assertSame(StockImage::REMOVED, $gone->state());
        $this->assertNull($gone->comp());
        $this->assertNull(app(StockImageStore::class)->forAsset(AssetRef::statamic('assets', 'moved/one.png')));
    }

    public function test_a_paid_librarys_image_is_never_shown_to_a_model(): void
    {
        $this->signIn();
        $this->record('covers/one.png', 'getty');

        $this->photoLibrary([
            'api.unsplash.com/search/photos*' => ['results' => [$this->unsplash('light1'), $this->unsplash('light2')]],
            'images.unsplash.com/*' => $this->png(),
        ]);
        $this->ai->respond('photo-picker', '1: a lighthouse');

        $request = StoredRequest::start(Format::Statamic, StoredRequest::FIND, (string) User::current()->id(), ['slot' => ['stories', null, 'cover', null, null, 'A Tale', '', '']]);
        $request->terms = ['lighthouse'];
        app(ImageRequestStore::class)->save($request);

        $this->runJob(new FindImages($request->id));

        // Two covers are on the site; only the one that isn't Getty's is a reference.
        $this->ai->assertSent('photo-picker', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'The first 1 image(s) are the references'));

        // Nor is it a sample for the image style guide.
        $samples = app(ImageStudio::class)->samples('stories');
        $this->assertSame(['Two'], array_column($samples, 'entry'));
    }

    public function test_the_usages_command_rescans_every_entry(): void
    {
        $record = $this->record('covers/one.png', 'getty');

        $this->artisan('ghostwriter:stock-usages')->expectsOutputToContain('1 stock image record updated')->assertSuccessful();

        $this->assertSame([['one', 'cover', 'Cover', true]], $this->usages(app(StockImageStore::class)->find($record->id)));
    }

    /**
     * A ledger record for an asset already in the container.
     */
    private function record(string $path, string $library, bool $free = false): StockImage
    {
        $photo = new Photo($library, 'p'.crc32($path), 'https://images.example.com/x.jpg', 'Kim Lee', null, 'Royalty-free', title: 'Rocks', offer: $free ? null : Offer::paid(Cost::units(1, Cost::DOWNLOAD)));
        $images = app(StockImages::class);
        $ref = AssetRef::statamic('assets', $path);

        return $free ? $images->recordFree($photo, $ref) : $images->recordPreview($photo, $ref, null, null);
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: ?string, 3: bool}>
     */
    private function usages(StockImage $image): array
    {
        return array_map(fn ($usage) => [(string) $usage->ownerId, $usage->field, $usage->label, $usage->live], $image->usages());
    }

    /**
     * @return array<string, mixed>
     */
    private function unsplash(string $id, ?string $alt = null): array
    {
        return ['id' => $id, 'urls' => ['small' => "https://images.unsplash.com/{$id}.jpg", 'raw' => "https://images.unsplash.com/{$id}?x=1"], 'user' => ['name' => 'Ada'], 'links' => ['html' => "https://unsplash.com/photos/{$id}", 'download_location' => "https://api.unsplash.com/photos/{$id}/download"], 'alt_description' => $alt];
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }
}
