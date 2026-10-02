<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequest as StoredRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Images\ContainerAssetSink;
use NineteenNinetyFour\Ghostwriter\Images\FieldSlot;
use NineteenNinetyFour\Ghostwriter\Jobs\FindImages;
use NineteenNinetyFour\Ghostwriter\Jobs\MakeImage;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * The Ghostwriter button on an assets field: finding, making and keeping a
 * picture for one field on a form.
 */
class FieldImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ghostwriter.keys.openai' => 'test-key', 'ghostwriter.images.unsplash_key' => 'unsplash-key', 'ghostwriter.images.openverse' => false, 'filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->workspace.'/assets', 'url' => '/assets']]);

        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();

        Collection::make('stories')->title('Stories')->save();

        Blueprint::make('story')->setNamespace('collections.stories')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'cover', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => 'Cover', 'folder' => 'covers']],
            ['handle' => 'brochure', 'field' => ['type' => 'assets', 'container' => 'assets', 'validate' => ['mimes:pdf']]],
            ['handle' => 'gallery', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 2, 'display' => 'Gallery']],
            ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => ['content' => ['display' => 'Content', 'sets' => [
                'grid_left' => ['display' => 'Grid — Left', 'fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                    ['handle' => 'picture', 'field' => ['type' => 'assets', 'container' => 'assets', 'display' => 'Picture']],
                ]],
                'grid_right' => ['display' => 'Grid — Right', 'fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                    ['handle' => 'picture', 'field' => ['type' => 'assets', 'container' => 'assets', 'display' => 'Picture']],
                ]],
            ]]]]],
        ]])->save();

        foreach (['one', 'two'] as $slug) {
            Storage::disk('assets')->put("covers/{$slug}.png", $this->png());
            Storage::disk('assets')->put("grids/{$slug}.png", $this->png());

            Entry::make()->collection('stories')->slug($slug)->published(true)->data([
                'title' => ucfirst($slug),
                'cover' => "covers/{$slug}.png",
                'blocks' => [['id' => 'g', 'type' => 'grid_right', 'enabled' => true, 'heading' => 'Hi', 'picture' => ["grids/{$slug}.png"]]],
            ])->save();
        }
    }

    public function test_a_slot_knows_its_label_references_and_folder_and_refuses_what_it_cannot_help_with(): void
    {
        $cover = FieldSlot::find('stories', null, 'cover', null, null, 'A Tale', '', 'Once upon a time.');

        $this->assertSame('Cover', $cover->label());
        $this->assertCount(2, $cover->references());
        $this->assertSame('covers', $cover->folder());

        // Inside a block: the same field in the same kind of set; failing
        // that, a sibling set of the same family.
        $left = FieldSlot::find('stories', null, 'blocks.0.picture', 'grid_left', null, 'A Tale', 'Beside the picture', 'All of it');

        $this->assertSame('Grid — Left: Picture', $left->label());
        $this->assertCount(2, $left->references());
        $this->assertSame('grids', $left->folder());

        $this->assertNull(FieldSlot::find('stories', null, 'brochure', null, null, '', '', ''), 'A PDF field takes no picture.');
        $this->assertNull(FieldSlot::find('stories', null, 'title', null, null, '', '', ''), 'Not an assets field.');
        $this->assertNull(FieldSlot::find('nowhere', null, 'cover', null, null, '', '', ''));
    }

    public function test_the_button_finds_photographs_from_the_words_around_the_field(): void
    {
        Bus::fake([FindImages::class]);
        $this->signIn();

        $slot = ['collection' => 'stories', 'path' => 'blocks.0.picture', 'set' => 'grid_left', 'title' => 'A Tale', 'block_text' => 'A lighthouse keeper', 'page_text' => 'All about the coast.'];

        $this->postJson(cp_route('ghostwriter.images.start'), $slot + ['mode' => 'find', 'words' => ''])
            ->assertOk()
            ->assertJsonPath('status', 'working')
            ->assertJsonPath('mode', 'find');

        Bus::assertDispatchedAfterResponse(FindImages::class);

        $request = app(ImageRequestStore::class)->find($this->postJson(cp_route('ghostwriter.images.start'), $slot + ['mode' => 'find', 'words' => 'harbour boats; stormy sea'])->json('id'));
        $this->assertSame(['harbour boats', 'stormy sea'], $request->terms);

        // Run the job: with no words typed, the scout chooses from the block and the page.
        $this->ai->respond('photo-researcher', 'lighthouse at dusk; coastal path; harbour boats');
        $this->ai->respond('photo-picker', "2: a lighthouse at dusk, like the others\n1: a lighthouse by day");

        $photo = fn (string $id, ?string $alt = null) => ['id' => $id, 'urls' => ['small' => "https://images.unsplash.com/{$id}.jpg", 'raw' => "https://images.unsplash.com/{$id}?x=1"], 'user' => ['name' => 'Ada'], 'links' => ['html' => "https://unsplash.com/photos/{$id}", 'download_location' => "https://api.unsplash.com/photos/{$id}/download"], 'alt_description' => $alt];

        $library = $this->photoLibrary([
            'api.unsplash.com/search/photos?query=lighthouse*' => ['results' => [$photo('light1'), $photo('light2', 'a white lighthouse on a cliff at dusk')]],
            'api.unsplash.com/search/photos?query=coastal*' => ['results' => [$photo('path1')]],
            'api.unsplash.com/search/photos?query=harbour*' => ['results' => [$photo('harb1')]],
            'api.unsplash.com/search/photos*' => ['results' => []],
            'api.unsplash.com/photos/light2' => $photo('light2', 'a white lighthouse on a cliff at dusk'),
            'api.unsplash.com/photos/light2/download' => [],
            'images.unsplash.com/*' => $this->png(),
        ]);

        $blank = $this->findRequest((string) User::current()->id(), [], ['stories', null, 'blocks.0.picture', 'grid_left', null, 'A Tale', 'A lighthouse keeper', 'All about the coast.']);

        $this->runJob(new FindImages($blank->id));

        $found = app(ImageRequestStore::class)->find($blank->id);

        $this->assertSame('done', $found->status);
        $this->assertSame(['lighthouse at dusk', 'coastal path', 'harbour boats'], $found->terms);
        // Judged: only the ones that fit, best first, and those marked.
        $this->assertSame(['light2', 'light1'], array_column($found->options, 'id'));
        $this->assertTrue($found->options[0]['picked']);
        $this->assertTrue($found->details['judged']);
        $this->assertTrue($found->details['with_references']);
        $this->assertFalse($found->details['none_fit']);

        $status = $this->getJson(cp_route('ghostwriter.images.status', $blank->id))->assertOk();
        $status->assertJsonPath('judged', true)->assertJsonPath('terms', ['lighthouse at dusk', 'coastal path', 'harbour boats']);

        $this->ai->assertSent('photo-researcher', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'The picture goes in: Grid — Left: Picture') && str_contains($prompt->prompt, 'A lighthouse keeper'));
        // The picker sees the grid pictures already on the other stories, and what the library says each photo shows.
        $this->ai->assertSent('photo-picker', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'The first 2 image(s) are the references') && str_contains($prompt->prompt, 'a white lighthouse on a cliff at dusk'));

        // Picking one: it is kept in the field's folder, named and titled from
        // what the library says it shows, with its credit; the field's new
        // value and meta come back for the form.

        $kept = $this->postJson(cp_route('ghostwriter.images.use', $blank->id), ['source' => 'unsplash', 'photo' => 'light2', 'term' => 'lighthouse at dusk', 'current' => ['assets::'.ContainerAssetSink::PATH]])
            ->assertOk()
            ->json();

        $this->assertStringStartsWith('grids/a-white-lighthouse-on-a-cliff-at-dusk-', $kept['asset']['path']);
        $this->assertSame('A white lighthouse on a cliff at dusk', $kept['asset']['title']);
        $asset = AssetContainer::find('assets')->asset($kept['asset']['path']);
        $this->assertSame('Ada on Unsplash', $asset->get('credit'));
        // The container's blueprint has an alt field, so the alt text goes in it.
        $this->assertSame('A white lighthouse on a cliff at dusk', $asset->get('alt'));
        $this->assertContains('api.unsplash.com/photos/light2/download', $this->photoRequests($library));
        // The placeholder made way; the field's meta knows the new asset.
        $this->assertSame(['assets::'.$kept['asset']['path']], $kept['value']);
        $this->assertArrayHasKey('data', $kept['meta']);

        // Not someone else's request.
        $other = $this->findRequest('someone-else', [], $blank->details['slot']);
        $this->getJson(cp_route('ghostwriter.images.status', $other->id))->assertForbidden();
    }

    public function test_the_button_makes_a_picture_to_look_at_first_then_keeps_it(): void
    {
        $this->signIn();

        $started = $this->post(cp_route('ghostwriter.images.start'), [
            'collection' => 'stories', 'path' => 'cover', 'title' => 'A Tale', 'page_text' => 'Once upon a time.',
            'mode' => 'make', 'direction' => 'A lighthouse at dusk',
            'source' => UploadedFile::fake()->createWithContent('logo.png', $this->png()),
        ], ['Accept' => 'application/json'])->assertOk()->json();

        $this->runJob(new MakeImage($started['id']));

        $status = $this->getJson(cp_route('ghostwriter.images.status', $started['id']))->assertOk()->json();

        $this->assertSame('done', $status['status']);
        $this->assertNotNull($status['preview_url']);
        $this->get($status['preview_url'])->assertOk();

        $this->ai->assertImageSent(fn (ImageRequest $prompt) => str_contains($prompt->prompt, 'A lighthouse at dusk') && count($prompt->references) === 3);

        $kept = $this->postJson(cp_route('ghostwriter.images.use', $started['id']), ['current' => ['assets::covers/one.png']])->assertOk()->json();

        $this->assertStringStartsWith('covers/a-lighthouse-at-dusk-', $kept['asset']['path']);
        // A single-image field gives up its current image.
        $this->assertSame(['assets::'.$kept['asset']['path']], $kept['value']);
    }

    public function test_photos_nobody_compared_with_the_site_are_not_called_the_best_match(): void
    {
        $this->signIn();
        $this->withoutKeys('anthropic');

        $photo = fn (string $id) => ['id' => $id, 'urls' => ['small' => "https://images.unsplash.com/{$id}.jpg"], 'user' => ['name' => 'Ada'], 'links' => ['html' => "https://unsplash.com/photos/{$id}"]];

        $this->photoLibrary([
            'api.unsplash.com/search/photos?query=boats*' => ['results' => [$photo('b1'), $photo('b2'), $photo('b3'), $photo('b4')]],
            'api.unsplash.com/search/photos?query=harbour*' => ['results' => [$photo('h1'), $photo('h2')]],
        ]);

        // No model: the top result of each search comes first, unmarked.
        $request = $this->findRequest((string) User::current()->id(), ['boats', 'harbour'], ['stories', null, 'gallery', null, null, 'A Tale', '', '']);

        $this->runJob(new FindImages($request->id));

        $found = app(ImageRequestStore::class)->find($request->id);

        $this->assertCount(6, $found->options);
        $this->assertSame(['b1', 'h1', 'b2'], array_slice(array_column($found->options, 'id'), 0, 3));
        $this->assertSame([], array_filter(array_column($found->options, 'picked')), 'Nothing was judged, so nothing is the best match.');
        $this->assertFalse($found->details['judged']);
        $this->ai->assertNotSent('photo-picker');
    }

    public function test_photos_are_judged_against_the_page_when_there_are_no_images_to_match(): void
    {
        $this->signIn();

        $photo = fn (string $id, string $alt) => ['id' => $id, 'urls' => ['small' => "https://images.unsplash.com/{$id}.jpg"], 'user' => ['name' => 'Ada'], 'links' => ['html' => "https://unsplash.com/photos/{$id}"], 'alt_description' => $alt];

        $this->photoLibrary([
            'api.unsplash.com/search/photos?query=boats*' => ['results' => [$photo('b1', 'fishing boats in a harbour'), $photo('b2', 'a toy boat in a bath')]],
            'api.unsplash.com/search/photos?query=harbour*' => ['results' => [$photo('h1', 'a harbour wall at low tide')]],
            'images.unsplash.com/*' => $this->png(),
        ]);

        // The gallery is empty on every other story, so there is nothing to match;
        // the model still checks each photo against the page's words.
        $this->ai->respond('photo-picker', "1: boats in a harbour\n3: the harbour wall");

        $request = $this->findRequest((string) User::current()->id(), ['boats', 'harbour'], ['stories', null, 'gallery', null, null, 'A Tale', '', 'A day in the fishing harbour.']);

        $this->runJob(new FindImages($request->id));

        $found = app(ImageRequestStore::class)->find($request->id);

        $this->assertSame(['b1', 'h1'], array_column($found->options, 'id'), 'The toy boat is left out.');
        $this->assertTrue($found->details['judged']);
        $this->assertFalse($found->details['with_references']);
        $this->ai->assertSent('photo-picker', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'There are no reference images') && str_contains($prompt->prompt, 'a toy boat in a bath'));
    }

    public function test_a_full_multi_image_field_is_left_alone_and_the_image_kept_in_the_container(): void
    {
        $this->signIn();

        $make = fn () => tap($this->postJson(cp_route('ghostwriter.images.start'), ['collection' => 'stories', 'path' => 'gallery', 'mode' => 'make', 'direction' => 'A boat'])->assertOk()->json(),
            fn (array $started) => $this->runJob(new MakeImage($started['id'])));

        // Room for one more: it goes in beside the one already there.
        $added = $this->postJson(cp_route('ghostwriter.images.use', $make()['id']), ['current' => ['assets::grids/one.png']])->assertOk()->json();
        $this->assertFalse($added['full']);
        $this->assertCount(2, $added['value']);

        // Full: the field is not touched, and the person is told where the image went.
        $refused = $this->postJson(cp_route('ghostwriter.images.use', $make()['id']), ['current' => ['assets::grids/one.png', 'assets::grids/two.png']])->assertOk()->json();
        $this->assertTrue($refused['full']);
        $this->assertArrayNotHasKey('value', $refused);
        $this->assertStringContainsString('This field is full', $refused['message']);
        $this->assertTrue(Storage::disk('assets')->exists($refused['asset']['path']), 'The image is still kept in the container.');

        // A placeholder does not count towards the limit.
        $placeholder = $this->postJson(cp_route('ghostwriter.images.use', $make()['id']), ['current' => ['assets::grids/one.png', 'assets::'.ContainerAssetSink::PATH]])->assertOk()->json();
        $this->assertFalse($placeholder['full']);
    }

    public function test_the_tools_on_offer_follow_the_keys_and_a_pdf_field_is_refused(): void
    {
        $this->signIn();

        $this->getJson(cp_route('ghostwriter.images.tools'))->assertOk()->assertJsonPath('find', true)->assertJsonPath('make', true)->assertJsonMissingPath('logo_card');

        $this->withoutKeys('openai');
        config(['ghostwriter.images.unsplash_key' => null]);
        $this->getJson(cp_route('ghostwriter.images.tools'))->assertJsonPath('find', false)->assertJsonPath('make', false);

        $this->postJson(cp_route('ghostwriter.images.start'), ['collection' => 'stories', 'path' => 'brochure', 'mode' => 'find'])->assertStatus(422);
    }

    public function test_the_button_does_not_put_an_image_where_the_person_could_not_upload(): void
    {
        Bus::fake();

        // Finding and making may be started (nothing is saved yet), but
        // keeping the result in the field may not.
        $this->signInWith(['access ghostwriter', 'view stories entries', 'edit stories entries']);

        $started = $this->postJson(cp_route('ghostwriter.images.start'), ['collection' => 'stories', 'path' => 'cover', 'mode' => 'make', 'direction' => 'A lighthouse'])->assertOk()->json();
        $this->runJob(new MakeImage($started['id']));

        $this->postJson(cp_route('ghostwriter.images.use', $started['id']), ['current' => []])->assertForbidden();

        $this->assertSame(['covers/one.png', 'covers/two.png'], Storage::disk('assets')->files('covers'));
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    /**
     * A search for one field, as the image button would start it.
     *
     * @param  array<int, string>  $terms
     * @param  array<int, mixed>  $slot
     */
    private function findRequest(string $user, array $terms, array $slot): StoredRequest
    {
        $request = StoredRequest::start(Format::Statamic, StoredRequest::FIND, $user, ['slot' => $slot]);
        $request->terms = $terms;

        return app(ImageRequestStore::class)->save($request);
    }
}
