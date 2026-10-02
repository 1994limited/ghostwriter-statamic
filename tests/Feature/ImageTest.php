<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaDescriber;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Drafts\EntryBuilder;
use NineteenNinetyFour\Ghostwriter\Images\ImageryGuide;
use NineteenNinetyFour\Ghostwriter\Images\ImageryState;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Jobs\GenerateImage;
use NineteenNinetyFour\Ghostwriter\Jobs\GenerateImageryGuide;
use NineteenNinetyFour\Ghostwriter\Jobs\RunSessionTurn;
use NineteenNinetyFour\Ghostwriter\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Sessions\SessionRepository;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Making images for a draft, modelled on the ones the site already uses.
 */
class ImageTest extends TestCase
{
    private const DRAFT = "title: A New Story\nsummary: What happened next.\nblocks:\n  - type: banner\n    heading: Hello\n  - type: text\n    body: Words.";

    protected function setUp(): void
    {
        parent::setUp();

        config(['ghostwriter.keys.openai' => 'test-key', 'filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->workspace.'/assets', 'url' => '/assets']]);

        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();

        Collection::make('stories')->title('Stories')->save();

        Blueprint::make('story')->setNamespace('collections.stories')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'summary', 'field' => ['type' => 'textarea']],
            ['handle' => 'cover', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => 'Cover']],
            ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => [
                'banner' => ['display' => 'Banner', 'fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                    ['handle' => 'picture', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1]],
                ]],
                'text' => ['display' => 'Text', 'fields' => [
                    ['handle' => 'body', 'field' => ['type' => 'textarea']],
                    ['handle' => 'aside', 'field' => ['type' => 'assets', 'container' => 'assets']],
                ]],
            ]]],
        ]])->save();

        foreach (['one', 'two'] as $slug) {
            Storage::disk('assets')->put("stories/{$slug}.png", $this->png());
            Storage::disk('assets')->put("banners/{$slug}.png", $this->png());

            Entry::make()->collection('stories')->slug($slug)->published(true)->data([
                'title' => ucfirst($slug),
                'cover' => "stories/{$slug}.png",
                'blocks' => [
                    ['id' => 'b', 'type' => 'banner', 'enabled' => true, 'heading' => 'Hi '.$slug, 'picture' => "banners/{$slug}.png"],
                    ['id' => 't', 'type' => 'text', 'enabled' => true, 'body' => 'Body of '.$slug],
                ],
            ])->save();
        }
    }

    public function test_the_draft_offers_the_image_fields_its_models_fill_in(): void
    {
        $this->signIn();

        $images = $this->getJson(cp_route('ghostwriter.sessions.show', $this->draftSession()->id))->assertOk()->json('images');

        // The cover, and the banner's picture. Nobody fills in the text
        // block's aside, so it is not part of how these pages look.
        $this->assertSame(['cover', 'blocks:banner:0:picture'], array_column($images, 'key'));
        $this->assertSame(['Cover', 'Banner: Picture'], array_column($images, 'label'));
        $this->assertSame([2, 2], array_column($images, 'references'));
        $this->assertSame(['empty', 'empty'], array_column($images, 'status'));
    }

    public function test_picked_entries_without_pictures_fall_back_on_the_rest_of_the_collection(): void
    {
        Entry::make()->collection('stories')->slug('bare')->published(false)->data(['title' => 'Bare', 'blocks' => [['id' => 't', 'type' => 'text', 'enabled' => true, 'body' => 'Words.']]])->save();

        $this->signIn();

        $session = $this->draftSession();
        $session->examples = [Entry::query()->where('slug', 'bare')->first()->id()];
        app(SessionRepository::class)->save($session);

        $images = $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))->json('images');

        $this->assertSame(['cover', 'blocks:banner:0:picture'], array_column($images, 'key'));
        $this->assertSame(2, $images[0]['references']);
    }

    public function test_no_image_controls_without_a_provider_that_makes_images(): void
    {
        $this->withoutKeys('openai');
        $this->signIn();

        $session = $this->draftSession();

        // The fields are still listed, for finding a photograph instead.
        $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))
            ->assertJsonPath('image_tools.generate', false)
            ->assertJsonPath('image_tools.search', ['openverse'])
            ->assertJsonCount(2, 'images');

        $this->postJson(cp_route('ghostwriter.sessions.image', $session->id), ['key' => 'cover'])->assertStatus(422);
        $this->assertNull(app(ImageStudio::class)->provider());

    }

    public function test_an_image_is_not_saved_where_the_person_could_not_upload(): void
    {
        config(['ghostwriter.images.unsplash_key' => 'unsplash-key']);
        Bus::fake();

        $this->signInWith(['access ghostwriter', 'view stories entries', 'edit stories entries', 'create stories entries']);

        $session = $this->draftSession();

        $this->postJson(cp_route('ghostwriter.sessions.photo', $session->id), ['key' => 'cover', 'source' => 'unsplash', 'id' => 'abc123'])->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.image', $session->id), ['key' => 'cover'])->assertForbidden();

        Bus::assertNotDispatched(GenerateImage::class);
        $this->assertSame(['stories/one.png', 'stories/two.png'], Storage::disk('assets')->files('stories'));
        $this->assertNull(app(SessionRepository::class)->find($session->id)->images['cover'] ?? null);
    }

    public function test_free_photographs_can_be_searched_for(): void
    {
        config(['ghostwriter.images.unsplash_key' => 'unsplash-key']);

        $library = $this->photoLibrary([
            'api.unsplash.com/search/photos*' => ['results' => [[
                'id' => 'abc123', 'urls' => ['small' => 'https://images.unsplash.com/small.jpg'],
                'user' => ['name' => 'Ada'], 'links' => ['html' => 'https://unsplash.com/photos/abc123'],
            ]]],
            'api.openverse.org/thumb.jpg' => $this->png(),
            'api.openverse.org/*' => ['results' => [[
                'id' => 'c0ffee', 'thumbnail' => 'https://api.openverse.org/thumb.jpg', 'url' => 'https://example.org/full.jpg',
                'creator' => 'Bo', 'license' => 'cc0', 'foreign_landing_url' => 'https://example.org/page',
            ]]],
            'images.unsplash.com/*' => $this->png(),
        ]);

        $this->ai->respond('photo-researcher', 'Lighthouse at dusk.');
        $this->ai->respond('photo-picker', "1: a lighthouse\n2: also a lighthouse");

        $this->signIn();

        $response = $this->getJson(cp_route('ghostwriter.sessions.photos', [$this->draftSession()->id, 'key' => 'cover']))->assertOk();

        // With nothing typed, the model chooses what to search for from the draft.
        $response->assertJsonPath('query', 'lighthouse at dusk')->assertJsonPath('judged', true)->assertJsonPath('with_references', true);
        $this->assertSame(['unsplash', 'openverse'], array_column($response->json('options'), 'source'));
        $this->assertSame('Ada on Unsplash', $response->json('options.0.credit'));
        $this->assertSame('CC0', $response->json('options.1.licence'));
        $this->ai->assertSent('photo-researcher', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'Page title: A New Story') && str_contains($prompt->prompt, 'Summary: What happened next.'));

        // Openverse is only ever asked for work free of conditions.
        $this->assertNotEmpty(array_filter($this->photoRequests($library), fn (string $address) => str_contains($address, 'api.openverse.org/v1/images/') && str_contains($address, 'license=cc0,pdm')));
    }

    public function test_a_chosen_photograph_is_saved_with_its_credit(): void
    {
        config(['ghostwriter.images.unsplash_key' => 'unsplash-key', 'ghostwriter.images.pixabay_key' => 'pixabay-key']);

        $library = $this->photoLibrary([
            'api.unsplash.com/photos/abc123' => [
                'id' => 'abc123',
                'urls' => ['small' => 'https://images.unsplash.com/small.jpg', 'raw' => 'https://images.unsplash.com/photo-1?ixid=1'],
                'user' => ['name' => 'Ada'],
                'links' => ['html' => 'https://unsplash.com/photos/abc123', 'download_location' => 'https://api.unsplash.com/photos/abc123/download'],
            ],
            'api.unsplash.com/photos/abc123/download' => [],
            'images.unsplash.com/*' => $this->png(),
            'pixabay.com/api/*' => ['hits' => [['id' => 42, 'webformatURL' => 'https://pixabay.com/get/small.png', 'largeImageURL' => 'https://pixabay.com/get/large.png', 'user' => 'Cy', 'pageURL' => 'https://pixabay.com/photos/42', 'tags' => 'red boat, harbour']]],
            'pixabay.com/get/*' => $this->png(),
        ]);

        $this->signIn();

        $session = $this->draftSession();

        $this->postJson(cp_route('ghostwriter.sessions.photo', $session->id), ['key' => 'cover', 'source' => 'unsplash', 'id' => 'abc123'])
            ->assertOk()
            ->assertJsonPath('images.0.status', 'done')
            ->assertJsonPath('images.0.credit', 'Ada on Unsplash');

        $path = app(SessionRepository::class)->find($session->id)->images['cover']['path'];

        // The library says nothing about this one, so it is named after the piece.
        $this->assertStringStartsWith('stories/a-new-story-', $path);
        Storage::disk('assets')->assertExists($path);
        $this->assertSame('Ada on Unsplash', AssetContainer::find('assets')->asset($path)->get('credit'));

        // Unsplash was told the photograph was used.
        $this->assertContains('api.unsplash.com/photos/abc123/download', $this->photoRequests($library));

        // Pixabay works the same way, looked up again by ID, and named from its tags.
        $this->postJson(cp_route('ghostwriter.sessions.photo', $session->id), ['key' => 'cover', 'source' => 'pixabay', 'id' => '42', 'term' => 'harbour boats'])
            ->assertOk()
            ->assertJsonPath('images.0.credit', 'Cy on Pixabay');

        $path = app(SessionRepository::class)->find($session->id)->images['cover']['path'];
        $this->assertStringStartsWith('stories/red-boat-harbour-', $path);
        $this->assertSame('Red boat, harbour', AssetContainer::find('assets')->asset($path)->get('title'));

        // A source that is not switched on, or a file that is not an image.
        $this->postJson(cp_route('ghostwriter.sessions.photo', $session->id), ['key' => 'cover', 'source' => 'pexels', 'id' => '1'])->assertStatus(422);
    }

    public function test_asking_for_an_image_starts_the_job(): void
    {
        Bus::fake([GenerateImage::class]);
        $this->signIn();

        $session = $this->draftSession();

        $this->postJson(cp_route('ghostwriter.sessions.image', $session->id), ['key' => 'nonsense'])->assertStatus(422);

        $this->post(cp_route('ghostwriter.sessions.image', $session->id), [
            'key' => 'cover',
            'direction' => 'A lighthouse at dusk',
            'source' => UploadedFile::fake()->createWithContent('logo.png', $this->png()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('images.0.status', 'working');

        Bus::assertDispatchedAfterResponse(GenerateImage::class, fn ($job) => $job->key === 'cover'
            && $job->direction === 'A lighthouse at dusk'
            && is_file($job->source));

        // Not twice at once.
        $this->postJson(cp_route('ghostwriter.sessions.image', $session->id), ['key' => 'cover'])->assertStatus(409);
    }

    public function test_an_image_is_made_from_the_sites_own_and_saved_beside_them(): void
    {

        $session = $this->draftSession();

        (new GenerateImage($session->id, 'blocks:banner:0:picture', 'A lighthouse at dusk'))->handle(app(SessionRepository::class), app(TypeRepository::class), app(ImageStudio::class));

        $image = app(SessionRepository::class)->find($session->id)->images['blocks:banner:0:picture'];

        $this->assertSame('done', $image['status']);
        $this->assertStringStartsWith('banners/a-new-story-', $image['path']);
        Storage::disk('assets')->assertExists($image['path']);

        // The two existing banner pictures went along as the style to match.
        $this->ai->assertImageSent(fn (ImageRequest $prompt) => str_contains($prompt->prompt, 'A lighthouse at dusk')
            && str_contains($prompt->prompt, 'Banner: Picture')
            && str_contains($prompt->prompt, 'The first 2 attached image(s)')
            && count($prompt->references) === 2);
    }

    public function test_a_failed_image_is_reported_and_costs_nothing_else(): void
    {
        $this->ai->respondWithImage(fn () => throw new \RuntimeException('The provider said no.'));

        $session = $this->draftSession();

        (new GenerateImage($session->id, 'cover'))->handle(app(SessionRepository::class), app(TypeRepository::class), app(ImageStudio::class));

        $session = app(SessionRepository::class)->find($session->id);

        $this->assertSame('failed', $session->images['cover']['status']);
        $this->assertSame('The provider said no.', $session->images['cover']['error']);
        $this->assertSame(self::DRAFT, $session->draft);
    }

    public function test_finished_images_go_into_the_form_with_the_draft(): void
    {
        $this->signIn();

        $session = $this->draftSession();
        $session->images = [
            'cover' => ['status' => 'done', 'path' => 'stories/one.png'],
            'blocks:banner:0:picture' => ['status' => 'done', 'path' => 'banners/two.png'],
            'blocks:text:0:aside' => ['status' => 'failed', 'error' => 'No.'],
        ];
        app(SessionRepository::class)->save($session);

        $values = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id))->assertOk()->json('values');

        $this->assertSame(['assets::stories/one.png'], (array) $values['cover']);
        $this->assertSame(['assets::banners/two.png'], (array) $values['blocks'][0]['picture']);
        $this->assertEmpty($values['blocks'][1]['aside'] ?? null);

        $this->postJson(cp_route('ghostwriter.sessions.entry', $session->id))->assertOk();

        $entry = Entry::query()->where('collection', 'stories')->where('slug', 'a-new-story')->first();

        $this->assertSame('stories/one.png', $entry->get('cover'));
        $this->assertSame('banners/two.png', $entry->get('blocks')[0]['picture']);
    }

    public function test_the_writer_is_told_how_to_arrange_images(): void
    {
        $instructions = app(Studio::class)->writerInstructions(app(TypeRepository::class)->find('any:stories'), '');

        foreach (['`cover`: Cover', '`blocks:banner:0:picture`: Banner, Picture', '`find`', '`fill`', '`make`', 'never say you cannot help'] as $expected) {
            $this->assertStringContainsString($expected, $instructions);
        }

        // Nobody fills in the text block's aside.
        $this->assertStringNotContainsString('aside', $instructions);

        // With no image model, making is not on offer.
        $this->withoutKeys('openai');

        $this->assertStringContainsString('cannot make new images', app(Studio::class)->writerInstructions(app(TypeRepository::class)->find('any:stories'), ''));
    }

    public function test_the_writer_offers_photographs_with_its_draft_and_fills_them_when_asked(): void
    {
        config(['ghostwriter.images.unsplash_key' => 'unsplash-key', 'ghostwriter.images.openverse' => false]);

        $photo = fn (string $id) => [
            'id' => $id, 'urls' => ['small' => "https://images.unsplash.com/{$id}-small.jpg", 'raw' => "https://images.unsplash.com/{$id}?ixid=1"],
            'user' => ['name' => 'Ada'], 'links' => ['html' => "https://unsplash.com/photos/{$id}"],
        ];

        $this->photoLibrary([
            'api.unsplash.com/search/photos?query=lighthouse*' => ['results' => [$photo('light1'), $photo('light2')]],
            'api.unsplash.com/search/photos?query=harbour*' => ['results' => [$photo('harbour1'), $photo('harbour2')]],
            'api.unsplash.com/search/photos?query=stormy*' => ['results' => [$photo('storm1')]],
            'api.unsplash.com/photos/harbour2' => $photo('harbour2'),
            'images.unsplash.com/*' => $this->png(),
        ]);

        $this->ai->respond('writer',
            '<reply>Here is the draft, with photographs to choose from.</reply>
<draft>
'.self::DRAFT.'
</draft>
<images>
cover | find | lighthouse at dusk; harbour boats; stormy sea
`blocks:banner:0:picture` | find | harbour boats
blocks:text:0:aside | find | nobody uses this
</images>',
            '<reply>I have put the best match in the cover.</reply>
<images>
cover | fill | lighthouse at dusk; harbour boats; stormy sea
</images>',
        );

        // Shown the site's covers and the five candidates, the judge likes
        // the fourth, the first and the fifth, in that order; for the banner,
        // the second of two; and the same again when asked to fill the cover.
        $this->ai->respond('photo-picker', "4: harbour\n1: lighthouse\n5: sea", '2: boats', "4: harbour\n1: lighthouse\n5: sea");

        $session = Session::start('any:stories', ['subject' => 'A new story.']);
        $session->addMessage('user', 'The brief.');
        $session = app(SessionRepository::class)->save($session);

        $run = fn () => (new RunSessionTurn($session->id))->handle(app(SessionRepository::class), app(TypeRepository::class), app(Studio::class), app(VoiceGuide::class));

        $run();

        $session = app(SessionRepository::class)->find($session->id);

        // Options are waiting without anyone pressing a button, and the
        // images block is not shown as part of the reply.
        $this->assertSame('Here is the draft, with photographs to choose from.', end($session->messages)['content']);
        $this->assertSame(['cover', 'blocks:banner:0:picture'], array_keys($session->images));
        $this->assertSame('lighthouse at dusk; harbour boats; stormy sea', $session->images['cover']['query']);
        // Only the ones that fit are offered, best first, and marked.
        $this->assertSame(['harbour2', 'light1', 'storm1'], array_column($session->images['cover']['options'], 'id'));
        $this->assertSame('harbour boats', $session->images['cover']['options'][0]['term']);
        $this->assertTrue($session->images['cover']['options'][0]['picked']);
        $this->assertTrue($session->images['cover']['judged']);

        // The judge saw the two existing covers, then the five candidates.
        $this->ai->assertSent('photo-picker', fn (TextRequest $prompt) => count($prompt->images) === 7 && str_contains($prompt->prompt, '4. from the search "harbour boats"'));

        // A field with two results is judged too.
        $this->assertSame(['harbour2'], array_column($session->images['blocks:banner:0:picture']['options'], 'id'));
        $this->assertSame('empty', $session->images['cover']['status']);

        $this->signIn();
        $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))->assertJsonPath('images.0.options.0.credit', 'Ada on Unsplash')->assertJsonPath('images.0.judged', true);

        // "Add the images for me": the best match goes straight in.
        $session->addMessage('user', 'Please add the cover image for me.');
        app(SessionRepository::class)->save($session);

        $run();

        $session = app(SessionRepository::class)->find($session->id);

        $this->assertSame('done', $session->images['cover']['status']);
        Storage::disk('assets')->assertExists($session->images['cover']['path']);
        $this->assertSame(['light1', 'storm1'], array_column($session->images['cover']['options'], 'id'));
        $this->assertSame(self::DRAFT, $session->draft);
    }

    public function test_one_fields_image_can_be_used_in_another(): void
    {
        $this->signIn();

        $session = $this->draftSession();
        $session->images = [
            'cover' => ['status' => 'done', 'path' => 'stories/one.png', 'url' => '/assets/stories/one.png', 'credit' => 'Ada on Unsplash'],
            'blocks:banner:0:picture' => ['status' => 'empty', 'query' => 'harbour boats', 'options' => [['id' => 'kept']]],
        ];
        app(SessionRepository::class)->save($session);

        $copy = fn (string $key, string $from) => $this->postJson(cp_route('ghostwriter.sessions.image.copy', $session->id), ['key' => $key, 'from' => $from]);

        $copy('blocks:banner:0:picture', 'cover')->assertOk()->assertJsonPath('images.1.status', 'done')->assertJsonPath('images.1.credit', 'Ada on Unsplash');

        $banner = app(SessionRepository::class)->find($session->id)->images['blocks:banner:0:picture'];

        // Same file, and the photographs it was offered are still there.
        $this->assertSame('stories/one.png', $banner['path']);
        $this->assertSame([['id' => 'kept']], $banner['options']);

        $values = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id))->json('values');

        $this->assertSame((array) $values['cover'], (array) $values['blocks'][0]['picture']);

        // Not from itself, and not from a field with nothing in it.
        $copy('cover', 'cover')->assertStatus(422);
        $session->images = [];
        app(SessionRepository::class)->save($session);
        $copy('cover', 'blocks:banner:0:picture')->assertStatus(422);
    }

    public function test_an_image_field_tied_to_a_folder_is_a_choice_the_writer_can_make(): void
    {
        Storage::disk('assets')->put('logos/acme.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        Storage::disk('assets')->put('logos/globex.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $blueprint = Blueprint::make('partner')->setNamespace('collections.stories')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'logo', 'field' => ['type' => 'assets', 'container' => 'assets', 'folder' => 'logos', 'max_files' => 1]],
            ['handle' => 'tint', 'field' => ['type' => 'color']],
        ]]);

        $schema = app(SchemaReader::class)->read($blueprint);
        $logo = collect($schema)->firstWhere('handle', 'logo');

        $this->assertSame('choice', $logo['kind']);
        $this->assertSame(['logos/acme.svg' => 'acme.svg', 'logos/globex.svg' => 'globex.svg'], $logo['options']);
        $this->assertStringContainsString('`logo` (one of logos/acme.svg, logos/globex.svg)', app(SchemaDescriber::class)->describe($schema, []));

        // The file's name is enough, and a colour is written as its hex.
        $built = app(EntryBuilder::class)->build(['title' => 'Globex', 'logo' => 'globex.svg', 'tint' => '#ff2d20'], $schema)['data'];

        $this->assertSame('logos/globex.svg', $built['logo']);
        $this->assertSame('#ff2d20', $built['tint']);
    }

    public function test_the_image_style_guide_is_written_from_the_sites_images(): void
    {
        // A third image, so there are enough to call a style.
        Storage::disk('assets')->put('stories/three.png', $this->png());
        Entry::make()->collection('stories')->slug('three')->published(true)->data(['title' => 'Three', 'cover' => 'stories/three.png'])->save();

        $this->ai->respond('imagery-analyst', '<document>**What they are.** Bright photographs of finished things.</document>');

        (new GenerateImageryGuide(['stories', 'nowhere']))->handle(app(ImageStudio::class), app(Studio::class), app(ImageryGuide::class), app(ImageryState::class));

        $guide = app(ImageryGuide::class);

        $this->assertSame("# Image style\n\n## Stories\n\n**What they are.** Bright photographs of finished things.\n", $guide->get());
        $this->assertSame('**What they are.** Bright photographs of finished things.', $guide->for('Stories'));
        $this->assertSame('', $guide->for('Elsewhere'));
        $this->assertSame(ImageryState::IDLE, app(ImageryState::class)->get()['status']);

        // The analyst was shown the images, labelled by field and entry.
        $this->ai->assertSent('imagery-analyst', fn (TextRequest $prompt) => count($prompt->images) === 5
            && str_contains($prompt->prompt, 'Section: Stories')
            && str_contains($prompt->prompt, 'Banner: Picture, on "One"'));

        // The writer is now told the house style, and how to choose for an idea.
        $instructions = app(Studio::class)->writerInstructions(app(TypeRepository::class)->find('any:stories'), '');

        $this->assertStringContainsString('Bright photographs of finished things.', $instructions);
        $this->assertStringContainsString('stands for the argument', $instructions);
    }

    public function test_the_image_style_screen_starts_the_job_and_saves_edits(): void
    {
        Bus::fake([GenerateImageryGuide::class]);
        $this->signIn();

        $this->postJson(cp_route('ghostwriter.imagery.scan'), ['collections' => ['stories']])
            ->assertOk()
            ->assertJsonPath('status', ImageryState::WORKING);

        Bus::assertDispatchedAfterResponse(GenerateImageryGuide::class, fn ($job) => $job->collections === ['stories']);

        $this->patchJson(cp_route('ghostwriter.imagery.update'), ['document' => "## Stories\n\nNever people."])
            ->assertOk()
            ->assertJsonPath('exists', true);

        $this->assertSame('Never people.', app(ImageryGuide::class)->for('Stories'));
    }

    public function test_the_judge_can_turn_everything_down_and_say_what_to_look_for(): void
    {
        config(['ghostwriter.images.unsplash_key' => 'unsplash-key', 'ghostwriter.images.openverse' => false]);

        $photo = fn (string $id) => ['id' => $id, 'urls' => ['small' => "https://images.unsplash.com/{$id}.jpg"], 'user' => ['name' => 'Ada'], 'links' => ['html' => "https://unsplash.com/photos/{$id}"]];

        $this->photoLibrary([
            'api.unsplash.com/search/photos?query=scaffolding*' => ['results' => array_map($photo, ['scaf1', 'scaf2', 'scaf3', 'scaf4'])],
            'api.unsplash.com/search/photos?query=mended*' => ['results' => array_map($photo, ['pot1', 'pot2', 'pot3', 'pot4'])],
            'api.unsplash.com/search/photos*' => ['results' => []],
            'images.unsplash.com/*' => $this->png(),
        ]);

        $this->ai->respond('photo-picker', 'none: mended pottery gold; restored classic car', '2, 1, 3');

        $session = $this->draftSession();
        $type = app(TypeRepository::class)->find('any:stories');

        $results = app(ImageStudio::class)->photos($session, $type, 'cover', 'scaffolding building');

        // The second round's picks are what is offered.
        $this->assertSame(['pot2', 'pot1', 'pot3'], array_map(fn ($photo) => $photo->id, $results->photos));
        $this->assertSame('mended pottery gold', $results->photos[0]->term);
        $this->assertTrue($results->retried);
        $this->assertFalse($results->noneFit);
        $this->assertSame(['scaffolding building', 'mended pottery gold', 'restored classic car'], $results->terms);

        // Nothing fits after the second round either: everything found is
        // offered, unranked, and nothing is called the best match.
        $this->ai->reset('photo-picker');
        $this->ai->respond('photo-picker', 'none: mended pottery gold; restored classic car', 'none: something else');

        $offered = ImageStudio::offered(app(ImageStudio::class)->photos($session, $type, 'cover', 'scaffolding building'));

        $this->assertTrue($offered['none_fit']);
        $this->assertFalse($offered['judged']);
        $this->assertCount(8, $offered['options']);
        $this->assertSame([], array_filter(array_column($offered['options'], 'picked')));
    }

    private function draftSession(): Session
    {
        $session = Session::start('any:stories', ['subject' => 'A new story.']);
        $session->draft = self::DRAFT;

        return app(SessionRepository::class)->save($session);
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }
}
