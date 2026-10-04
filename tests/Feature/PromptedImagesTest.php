<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Which empty fields Finish this page counts on Statamic: a required plain
 * field is Statamic's to report; an empty image the page looks like it
 * needs (required, or filled on most of the collection's published
 * entries) is counted and brings the guide out; an optional image few
 * entries use isn't. The collection's fill rates are counted again when
 * one of its entries is saved.
 */
class PromptedImagesTest extends TestCase
{
    private const BODY = 'Our new roof garden sits above the café, with raised beds of herbs for the kitchen and a few tables among them.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->workspace.'/assets', 'url' => '/assets']]);
        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();
        Storage::disk('assets')->put('garden.jpg', 'jpg');

        Collection::make('stories')->title('Journal')->routes('/{slug}')->save();
    }

    protected function tearDown(): void
    {
        Blueprint::find('collections.stories.story')?->delete();
        Collection::findByHandle('stories')?->delete();

        parent::tearDown();
    }

    public function test_an_empty_required_hero_image_is_counted_with_its_reason(): void
    {
        $this->blueprint(heroRequired: true);
        $this->signIn();

        $report = $this->check(['title' => 'A roof garden for a cafe in Newcastle', 'body' => self::BODY, 'summary' => '']);

        $this->assertSame(1, $report['count'], json_encode($report['gaps']));
        $this->assertSame(1, $report['prompting'], 'It brings the guide out on load, so the header shows it.');
        $this->assertSame(['hero_image'], array_column($report['gaps'], 'field'), 'The empty required summary is Statamic\'s to report.');
        $gap = $report['gaps'][0];
        $this->assertSame(['image-empty', 'prompt', 'required'], [$gap['kind'], $gap['severity'], $gap['meta']['why']]);
        $this->assertSame('Hero image', $gap['label']);
        $this->assertSame('Hero image is required. Add one?', $gap['message']);
        $this->assertSame(['Find a photo', 'Choose from Assets'], array_column($gap['fixes'], 'label'));
    }

    public function test_a_new_untouched_entry_is_not_prompted(): void
    {
        $this->blueprint(heroRequired: true);
        $this->signIn();

        $this->assertSame(0, $this->check(['title' => 'A roof garden'])['count']);
    }

    public function test_an_optional_image_most_entries_have_is_prompted_and_one_few_have_is_not(): void
    {
        $this->blueprint(heroRequired: false, image: 'picture');
        $this->signIn();

        foreach (['market-day', 'new-menu', 'late-opening', 'bees'] as $slug) {
            Entry::make()->id($slug)->collection('stories')->blueprint('story')->slug($slug)->published(true)->data(['title' => $slug, 'body' => self::BODY])->save();
        }

        $this->assertSame(0, $this->check(['title' => 'A roof garden', 'body' => self::BODY])['count'], 'No story has a picture: no gap.');

        // Saving entries in the collection counts its fill rates again.
        foreach (['market-day', 'new-menu', 'late-opening'] as $slug) {
            Entry::find($slug)->set('picture', 'garden.jpg')->save();
        }

        $report = $this->check(['title' => 'A roof garden', 'body' => self::BODY]);
        $this->assertSame(1, $report['count'], json_encode($report['gaps']));
        $this->assertSame('siblings', $report['gaps'][0]['meta']['why']);
        $this->assertSame(0.75, $report['gaps'][0]['meta']['filled']);
        $this->assertSame('Picture is empty, but most Journal entries have one. Add one?', $report['gaps'][0]['message']);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function check(array $values): array
    {
        return $this->postJson(cp_route('ghostwriter.finish.check'), ['collection' => 'stories', 'blueprint' => 'story', 'values' => $values])->assertOk()->json();
    }

    private function blueprint(bool $heroRequired, string $image = 'hero_image'): void
    {
        Blueprint::make('story')->setNamespace('collections.stories')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]],
            ['handle' => $image, 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => $image === 'hero_image' ? 'Hero image' : 'Picture', 'validate' => $heroRequired ? ['required'] : []]],
            ['handle' => 'body', 'field' => ['type' => 'textarea', 'display' => 'Body']],
            ['handle' => 'summary', 'field' => ['type' => 'textarea', 'display' => 'Summary', 'validate' => ['required']]],
        ]])->save();
    }
}
