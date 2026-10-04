<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Suggest edits, server side: the free findings at once, a review queued
 * after the confirm and read from a fake reviewer, decisions shared and
 * kept, Write another, and "Save to the image". Nothing is ever saved to
 * the entry; only alt text, on the asset, after its confirm.
 */
class SuggestEditsTest extends TestCase
{
    private const LONG = 'In terms of the actual process involved, what typically happens is that we will first of all come out and visit the garden in person, after which we will then go away and produce a concept.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->workspace.'/assets', 'url' => '/assets']]);
        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();
        Storage::disk('assets')->put('materials.jpg', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        Asset::make()->container('assets')->path('materials.jpg')->save();

        Collection::make('suggest_pages')->title('Pages')->routes('/{slug}')->save();
        Blueprint::make('suggest_page')->setNamespace('collections.suggest_pages')->setContents(['tabs' => [
            'main' => ['display' => 'Content', 'sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'page_builder', 'field' => ['type' => 'replicator', 'display' => 'Page builder', 'sets' => ['main' => ['sets' => [
                    'hero' => ['display' => 'Hero', 'fields' => [
                        ['handle' => 'eyebrow', 'field' => ['type' => 'text', 'display' => 'Eyebrow']],
                        ['handle' => 'heading', 'field' => ['type' => 'text', 'display' => 'Heading']],
                    ]],
                ]]]]],
                ['handle' => 'body', 'field' => ['type' => 'bard', 'display' => 'Text', 'buttons' => ['bold', 'link']]],
                ['handle' => 'image', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => 'Image']],
            ]]]],
            'seo' => ['display' => 'SEO', 'sections' => [['fields' => [
                ['handle' => 'meta_description', 'field' => ['type' => 'textarea', 'display' => 'SEO description']],
            ]]]],
        ]])->save();

        Entry::make()->id('services')->collection('suggest_pages')->slug('services')->published(true)->data([
            'title' => 'Services',
            'updated_at' => now()->subMonths(30)->getTimestamp(),
            'page_builder' => [['id' => 'h1', 'type' => 'hero', 'enabled' => true, 'eyebrow' => 'New for 2024: winter care visits', 'heading' => 'We leverage our expertise to deliver bespoke garden solutions']],
            'body' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A full design for your garden from our team of 6 designers. '.self::LONG]]],
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'See '],
                    ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::gone']]], 'text' => 'our 2023 show garden'],
                    ['type' => 'text', 'text' => '.'],
                ]],
            ],
            'image' => 'materials.jpg',
            'meta_description' => str_repeat('From a single planting plan to a full design and build, ', 4),
        ])->saveQuietly();
    }

    protected function tearDown(): void
    {
        Blueprint::find('collections.suggest_pages.suggest_page')?->delete();

        parent::tearDown();
    }

    private function guide(array $values = []): array
    {
        return $this->postJson(cp_route('ghostwriter.suggest.guide'), ['entry' => 'services', 'values' => $values])->assertOk()->json();
    }

    /** A reviewer that answers about this page, whatever its unit numbers. */
    private function reviewer(): void
    {
        File::ensureDirectoryExists(dirname(config('ghostwriter.voice.path')));
        File::put(config('ghostwriter.voice.path'), "# Northfold\n\n## What this voice never does\n\nNo jargon: never leverage, bespoke or solutions.\n");

        $this->ai->respond('reviewer', function (TextRequest $request) {
            preg_match_all('/<unit id="(u\d+)" field="([^"]+)"/', $request->prompt, $units, PREG_SET_ORDER);
            $unit = fn (string $field) => collect($units)->first(fn ($m) => str_contains($m[2], $field))[1] ?? 'u1';
            $answers = [];

            // Each candidate the free checks found, judged: the alt text and
            // the dated eyebrow kept with words, anything else fine as it is.
            preg_match_all('/^(f\d+) (\S+) (\S+)(?: under "[^"]*")?(?: "([^"]*)")?/m', $request->prompt, $candidates, PREG_SET_ORDER);

            foreach ($candidates as $m) {
                [$number, $category, $where, $quote] = [$m[1], $m[2], $m[3], $m[4] ?? ''];

                $answers[] = match (true) {
                    $category === 'accessibility' => ['finding' => $number, 'category' => $category, 'unit' => $where, 'reason' => 'Describes the photo.', 'source' => ['kind' => 'image'], 'replacement' => 'Stone, gravel and timber samples on a bench'],
                    $category === 'out-of-date' && str_contains((string) $quote, 'winter care visits') => ['finding' => $number, 'category' => $category, 'unit' => $where, 'quote' => $quote, 'reason' => 'Winter visits are not new any more.', 'source' => ['kind' => 'finding'], 'replacement' => 'Winter care visits', 'alternatives' => ['Our winter care visits']],
                    default => ['finding' => $number, 'drop' => 'Fine in context.'],
                };
            }

            $answers[] = ['category' => 'voice', 'unit' => $unit('Heading'), 'quote' => 'We leverage our expertise to deliver bespoke garden solutions', 'reason' => 'Three words the guide rules out.', 'source' => ['kind' => 'voice-guide', 'heading' => 'What this voice never does'], 'replacement' => 'We design gardens and help them grow', 'alternatives' => ['Gardens designed, planted and looked after', 'We design and plant gardens']];

            return "<suggestions>\n".json_encode(['suggestions' => $answers])."\n</suggestions>";
        });
    }

    public function test_free_findings_are_only_candidates_until_the_review_has_judged_them(): void
    {
        $this->signIn();
        $guide = $this->guide();

        $this->assertNull($guide['review']);
        $this->assertSame(1, $guide['calls']);
        $this->assertSame([], $guide['suggestions'], 'Nothing reaches the editor unjudged.');
        $this->assertGreaterThanOrEqual(3, $guide['candidates']);
        $this->assertSame([], $this->ai->requests(), 'No model.');
    }

    public function test_a_review_runs_after_the_confirm_and_nothing_is_saved_to_the_entry(): void
    {
        $this->signIn();
        $before = Entry::find('services')->data()->all();
        $this->reviewer();

        $started = $this->postJson(cp_route('ghostwriter.suggest.start'), ['entry' => 'services', 'values' => []])->assertOk()->json();
        $this->assertTrue($started['running']);

        $review = app(EditReviewStore::class)->latestFor(new EntryRef('suggest_pages', 'services', 'default'));

        $guide = $this->guide();
        $this->assertSame('ready', $guide['review']['status'], (string) $guide['review']['error']);
        $voice = collect($guide['suggestions'])->firstWhere('category', 'voice');
        $this->assertSame('We design gardens and help them grow', $voice['replacement']);
        $this->assertCount(2, $voice['alternatives']);
        $this->assertSame('page_builder.0.heading', $voice['dotted']);
        $this->ai->assertSent('reviewer');
        $this->assertCount(1, $this->ai->prompted('reviewer'));

        $this->postJson(cp_route('ghostwriter.suggest.decide', $review->id), ['decisions' => [
            ['suggestion' => $voice['id'], 'state' => 'accepted', 'text' => 'We design gardens and help them grow'],
        ]])->assertOk();

        $this->assertSame($before, Entry::find('services')->data()->all(), 'The entry is never saved.');
    }

    public function test_a_dismissal_is_kept_for_the_next_review(): void
    {
        $this->signIn();
        $this->reviewer();
        $this->postJson(cp_route('ghostwriter.suggest.start'), ['entry' => 'services'])->assertOk();
        $review = app(EditReviewStore::class)->latestFor(new EntryRef('suggest_pages', 'services', 'default'));

        $old = collect($this->guide()['suggestions'])->firstWhere('category', 'voice');
        $this->postJson(cp_route('ghostwriter.suggest.decide', $review->id), ['decisions' => [['suggestion' => $old['id'], 'state' => 'dismissed']]])->assertOk();

        $this->assertNull(collect($this->guide()['suggestions'])->first(fn ($s) => $s['id'] === $old['id'] && $s['state'] === 'open'));

        // Undo opens it again.
        $this->postJson(cp_route('ghostwriter.suggest.decide', $review->id), ['decisions' => [['suggestion' => $old['id'], 'state' => 'open']]])->assertOk();
        $this->assertSame('open', collect($this->guide()['suggestions'])->firstWhere('id', $old['id'])['state']);
    }

    public function test_save_to_the_image_writes_the_alt_text_and_undo_puts_it_back(): void
    {
        config(['statamic.editions.pro' => true]);
        $this->signInWith(['access ghostwriter', ...self::WRITER_PERMISSIONS]);
        $this->reviewer();
        $this->postJson(cp_route('ghostwriter.suggest.start'), ['entry' => 'services'])->assertOk();
        $review = app(EditReviewStore::class)->latestFor(new EntryRef('suggest_pages', 'services', 'default'));

        $alt = collect($this->guide()['suggestions'])->firstWhere('scope', 'asset');
        $this->assertSame('materials.jpg', $alt['asset']['filename']);
        $this->assertSame(1, $alt['asset']['uses']);

        $this->assertFalse($alt['asset']['canEdit']);
        $this->postJson(cp_route('ghostwriter.suggest.alt', $review->id), ['suggestion' => $alt['id'], 'alt' => 'Stone'])->assertForbidden();
        $this->signInWith(['access ghostwriter', ...self::WRITER_PERMISSIONS, 'edit assets assets']);

        $saved = $this->postJson(cp_route('ghostwriter.suggest.alt', $review->id), ['suggestion' => $alt['id'], 'alt' => 'Stone, gravel and timber samples'])->assertOk()->json();
        $this->assertSame('', $saved['before']);
        $this->assertSame('Stone, gravel and timber samples', Asset::find('assets::materials.jpg')->get('alt'));

        $this->postJson(cp_route('ghostwriter.suggest.unalt', $review->id), ['suggestion' => $alt['id'], 'before' => ''])->assertOk();
        $this->assertNull(Asset::find('assets::materials.jpg')->get('alt'));
    }

    public function test_write_another_is_one_small_call(): void
    {
        $this->signIn();
        $this->reviewer();
        $this->postJson(cp_route('ghostwriter.suggest.start'), ['entry' => 'services'])->assertOk();
        $review = app(EditReviewStore::class)->latestFor(new EntryRef('suggest_pages', 'services', 'default'));
        $voice = collect($this->guide()['suggestions'])->firstWhere('category', 'voice');

        $this->ai->respond('reworder', "<versions>\n<version>We plan gardens and help them grow</version>\n</versions>");
        $versions = $this->postJson(cp_route('ghostwriter.suggest.another', $review->id), ['entry' => 'services', 'suggestion' => $voice['id']])->assertOk()->json('versions');

        $this->assertSame(['We plan gardens and help them grow'], $versions);
        $this->assertCount(1, $this->ai->prompted('reworder'));
        $this->assertSame(['We plan gardens and help them grow'], collect($this->guide()['suggestions'])->firstWhere('id', $voice['id'])['versions']);
    }

    public function test_only_people_who_can_edit_the_entry_get_a_review(): void
    {
        $this->signIn(permitted: false);
        $this->postJson(cp_route('ghostwriter.suggest.guide'), ['entry' => 'services'])->assertForbidden();
        $this->postJson(cp_route('ghostwriter.suggest.start'), ['entry' => 'services'])->assertForbidden();
        $this->ai->assertNothingSent();
    }
}
