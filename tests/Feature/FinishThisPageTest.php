<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use NineteenNinetyFour\Ghostwriter\Http\Controllers\FinishController;
use NineteenNinetyFour\Ghostwriter\Images\ContainerAssetSink;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Statamic;

/**
 * Finish this page, server side: the check behind the count by Save and
 * the guide finds each kind of gap in the form's values, for nothing; the
 * gaps come back with a draft put into the form; the guide's state is
 * remembered per person; and a fix that writes asks the model once, never
 * for a fact.
 */
class FinishThisPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->workspace.'/assets', 'url' => '/assets']]);
        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();
        (new ContainerAssetSink)->placeholder(Field::fromSpec(['handle' => 'x', 'kind' => 'reference', 'container' => 'assets']), fn () => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

        Collection::make('pages')->title('Pages')->routes('/{slug}')->save();
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['tabs' => [
            'main' => ['display' => 'Content', 'sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]],
                ['handle' => 'hero_image', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => 'Hero image']],
                ['handle' => 'body', 'field' => ['type' => 'bard', 'display' => 'Body', 'buttons' => ['bold', 'link']]],
                ['handle' => 'page_builder', 'field' => ['type' => 'replicator', 'display' => 'Page builder', 'sets' => ['main' => ['sets' => [
                    'cta' => ['display' => 'Call to action', 'fields' => [
                        ['handle' => 'heading', 'field' => ['type' => 'text']],
                        ['handle' => 'button_link', 'field' => ['type' => 'link', 'display' => 'Button link']],
                    ]],
                ]]]]],
            ]]]],
            'seo' => ['display' => 'SEO', 'sections' => [['fields' => [
                ['handle' => 'summary', 'field' => ['type' => 'textarea', 'display' => 'Summary', 'validate' => ['required']]],
            ]]]],
        ]])->save();

        Entry::make()->id('contact')->collection('pages')->slug('contact')->published(true)->data(['title' => 'Contact us', 'summary' => 'How to reach us.'])->save();
        Entry::make()->id('capacitor')->collection('pages')->slug('capacitor')->published(false)->data([
            'title' => 'Capacitor apps',
            'hero_image' => ContainerAssetSink::PATH,
            'body' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Most projects take [[ask: how long a typical project takes]] from first call to launch. Some [[item]] text slipped in.']]],
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => '#gw-link:contact-page']]], 'text' => 'Talk to us about Capacitor'],
                    ['type' => 'text', 'text' => ' or read '],
                    ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::gone']]], 'text' => 'the old page'],
                    ['type' => 'text', 'text' => '.'],
                ]],
            ],
            'page_builder' => [
                ['id' => 'cta1', 'type' => 'cta', 'enabled' => true, 'heading' => 'Have a web product?', 'button_link' => '#gw-link:contact-page'],
            ],
        ])->save();
    }

    public function test_the_check_finds_each_gap_in_the_form_in_its_order_for_nothing(): void
    {
        $this->signIn();
        $entry = Entry::find('capacitor');
        $values = $entry->blueprint()->fields()->addValues($entry->data()->all())->preProcess()->values()->all();

        $report = $this->postJson(cp_route('ghostwriter.finish.check'), ['collection' => 'pages', 'entry' => 'capacitor', 'values' => $values])->assertOk()->json();

        $this->assertSame(
            ['hero_image:image-placeholder', 'body:ask', 'body:link', 'body:link-broken', 'body:leftover-token', 'page_builder.0.button_link:link', 'summary:required'],
            array_map(fn (array $gap) => $gap['dotted'].':'.$gap['kind'], $report['gaps']),
        );
        $this->assertSame(7, $report['count']);

        $gaps = collect($report['gaps'])->keyBy(fn (array $gap) => $gap['dotted'].':'.$gap['kind']);

        // In the editor's words, with the tab to open and the fixes to offer.
        $ask = $gaps['body:ask'];
        $this->assertSame('I left a gap in Body: how long a typical project takes. I didn\'t want to guess. What should it say?', $ask['message']);
        $this->assertSame('Fill this in', $ask['speech']);
        $this->assertSame('main', $ask['tab']);
        $this->assertSame(['answer', 'write-around'], array_column($ask['fixes'], 'action'));
        $this->assertSame('[[ask: how long a typical project takes]]', $ask['meta']['match']);
        $this->assertSame('seo', $gaps['summary:required']['tab']);

        // A link to choose is matched to the Contact page by its words.
        $link = $gaps['page_builder.0.button_link:link'];
        $this->assertSame('page_builder/#cta1/button_link', $link['path']);
        $this->assertSame('link', $link['fixes'][0]['action']);
        $this->assertSame('entry::contact', $link['fixes'][0]['value']);
        $this->assertSame('Link to Contact us', $link['fixes'][0]['label']);
        $this->assertSame('Talk to us about Capacitor', $gaps['body:link']['meta']['words']);

        // Typing into the form is checked as it stands, unsaved.
        $values['summary'] = 'A summary now.';
        $values['hero_image'] = [];
        $after = $this->postJson(cp_route('ghostwriter.finish.check'), ['collection' => 'pages', 'entry' => 'capacitor', 'values' => $values])->assertOk()->json();
        $this->assertNotContains('summary:required', array_map(fn (array $gap) => $gap['dotted'].':'.$gap['kind'], $after['gaps']));
        $this->assertNotContains('hero_image:image-placeholder', array_map(fn (array $gap) => $gap['dotted'].':'.$gap['kind'], $after['gaps']));

        $this->ai->assertNothingSent();
        $this->assertTrue(Entry::find('capacitor')->get('hero_image') === ContainerAssetSink::PATH, 'Nothing was saved.');
    }

    public function test_a_place_is_named_once(): void
    {
        $this->assertSame('Text', EntryGaps::place('Text: Text'));
        $this->assertSame('Hero: Image', EntryGaps::place('Hero: Hero: Image'));
        $this->assertSame('Image: Caption', EntryGaps::place('Image: Caption'));
        $this->assertSame('I left a gap in Text: how long a visit lasts. I didn\'t want to guess. What should it say?', app(EntryGaps::class)->text(new Message('gaps.ask', ['label' => 'Text: Text', 'hint' => 'how long a visit lasts'])));
    }

    public function test_the_check_needs_the_entry_to_be_theirs_to_edit_and_ghostwriter_on_the_collection(): void
    {
        config(['statamic.editions.pro' => true]);
        $this->signIn(permitted: false);
        $this->postJson(cp_route('ghostwriter.finish.check'), ['collection' => 'pages', 'entry' => 'capacitor', 'values' => []])->assertForbidden();

        $this->signIn();
        config(['ghostwriter.collections' => ['journal']]);
        $this->postJson(cp_route('ghostwriter.finish.check'), ['collection' => 'pages', 'entry' => 'capacitor', 'values' => []])->assertNotFound();
    }

    public function test_the_guide_is_remembered_per_person_and_starts_minimised(): void
    {
        $user = $this->signIn();

        $this->assertSame('minimised', Statamic::jsonVariables(request())['ghostwriter']['finish']['guide']);
        $this->assertTrue(Statamic::jsonVariables(request())['ghostwriter']['finish']['open_after_draft']);

        $this->postJson(cp_route('ghostwriter.finish.guide'), ['state' => 'open'])->assertOk();
        $this->assertSame(FinishController::OPEN, User::find($user->id())->getPreference(FinishController::PREFERENCE));
        $this->assertSame('open', Statamic::jsonVariables(request())['ghostwriter']['finish']['guide']);

        $this->postJson(cp_route('ghostwriter.finish.guide'), ['state' => 'minimised'])->assertOk();
        $this->assertSame(FinishController::MINIMISED, User::find($user->id())->getPreference(FinishController::PREFERENCE));

        $this->postJson(cp_route('ghostwriter.finish.guide'), ['state' => 'sideways'])->assertStatus(422);
    }

    public function test_write_around_asks_once_and_never_for_a_fact(): void
    {
        $this->signIn();
        $entry = Entry::find('capacitor');
        $values = $entry->blueprint()->fields()->addValues($entry->data()->all())->preProcess()->values()->all();
        $report = $this->postJson(cp_route('ghostwriter.finish.check'), ['collection' => 'pages', 'entry' => 'capacitor', 'values' => $values])->json('gaps');
        $ask = collect($report)->firstWhere('kind', 'ask');

        $this->ai->respond('gap-filler', '<result>Most projects move quickly from first call to launch.</result>');

        $this->postJson(cp_route('ghostwriter.finish.fill'), ['collection' => 'pages', 'entry' => 'capacitor', 'values' => $values, 'gap' => $ask['id'], 'task' => 'write-around'])
            ->assertOk()
            ->assertJsonPath('text', 'Most projects move quickly from first call to launch.')
            ->assertJsonPath('match', '[[ask: how long a typical project takes]]');

        $this->ai->assertSent('gap-filler', fn (TextRequest $request) => str_contains($request->prompt, 'how long a typical project takes'));
        $this->assertCount(1, $this->ai->prompted('gap-filler'));

        // "Write it for me" is for prose, never a fact.
        $this->postJson(cp_route('ghostwriter.finish.fill'), ['collection' => 'pages', 'entry' => 'capacitor', 'values' => $values, 'gap' => $ask['id'], 'task' => 'write-for-me'])->assertStatus(422);
        $this->assertCount(1, $this->ai->prompted('gap-filler'), 'Refused before any model was asked.');
    }

    public function test_write_it_for_me_writes_a_summary_from_the_page(): void
    {
        $this->signIn();
        $entry = Entry::find('capacitor');
        $values = $entry->blueprint()->fields()->addValues($entry->data()->all())->preProcess()->values()->all();
        $summary = collect($this->postJson(cp_route('ghostwriter.finish.check'), ['collection' => 'pages', 'entry' => 'capacitor', 'values' => $values])->json('gaps'))->firstWhere('kind', 'required');

        $this->ai->respond('gap-filler', '<result>Capacitor apps from the web product you already have.</result>');

        $this->postJson(cp_route('ghostwriter.finish.fill'), ['collection' => 'pages', 'entry' => 'capacitor', 'values' => $values, 'gap' => $summary['id'], 'task' => 'write-for-me'])
            ->assertOk()
            ->assertJsonPath('text', 'Capacitor apps from the web product you already have.');

        $this->ai->assertSent('gap-filler', fn (TextRequest $request) => str_contains($request->prompt, 'Talk to us about Capacitor'));
    }

    public function test_a_draft_put_into_the_form_comes_with_its_gaps_and_the_session_keeps_them(): void
    {
        $this->signIn();
        config(['ghostwriter.collections' => []]);
        app(TypeRepository::class)->save(TypeRepository::make('pages', ['title' => 'Page', 'description' => 'A page.', 'collection' => 'pages', 'questions' => [], 'guidance' => '', 'checklist' => []]));

        $session = $this->makeSession('pages');
        $session->draft = "title: Visiting\nsummary: Plan a visit.\nbody: |\n  Tickets cost [[ask: adult ticket price]] for adults. [Book a visit](#gw-link:booking-page).";
        $session->status = Session::IDLE;
        $this->sessions()->save($session);

        $response = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['blueprint' => 'page'])->assertOk();

        $gaps = $response->json('gaps.gaps');
        $this->assertSame(['ask', 'link'], array_values(array_intersect(array_column($gaps, 'kind'), ['ask', 'link'])));
        $this->assertSame('adult ticket price', collect($gaps)->firstWhere('kind', 'ask')['hint']);
        $this->assertSame([], array_values(array_filter(array_column($gaps, 'kind'), fn ($kind) => $kind === 'required')), 'The draft filled the summary.');

        $this->assertNotNull($this->sessions()->find($session->id)->appliedAt);
        $this->ai->assertNothingSent();
    }
}
