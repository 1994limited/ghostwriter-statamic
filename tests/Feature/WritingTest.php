<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use NineteenNinetyFour\Ghostwriter\Actions\WriteWithGhostwriter;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\AnalyseCollection;
use NineteenNinetyFour\Ghostwriter\Jobs\RunSessionTurn;
use NineteenNinetyFour\Ghostwriter\Jobs\SuggestKinds;
use NineteenNinetyFour\Ghostwriter\Jobs\Waiting;
use NineteenNinetyFour\Ghostwriter\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Sessions\SessionRepository;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Types\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\Types\TypeState;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;
use NineteenNinetyFour\Ghostwriter\Widgets\Ghostwriter;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Learning a collection, the questionnaire, the conversation, and handing
 * the draft to a publish form or straight to an entry.
 */
class WritingTest extends TestCase
{
    private const DRAFT = "title: What Does a Website Cost?\nsummary: Why quotes vary, and what moves the number.\npage_builder:\n  - type: hero\n  - type: long_form\n    content: |\n      ## Why are the quotes so far apart?\n\n      Because they are for **different** websites.\n  - type: related";

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeArticlesCollection();

        foreach (['one', 'two', 'three'] as $slug) {
            $this->makeArticle($slug, ucfirst($slug), 'A paragraph about '.$slug.' that says something specific enough to be worth reading twice over.');
        }
    }

    public function test_learning_a_collection_saves_a_content_type(): void
    {
        $this->ai->respond('type-analyst', "<type>\ntitle: Project article\ndescription: A write-up of one project.\nquestions:\n  - handle: what\n    label: What was built?\n    type: textarea\n    required: true\n  - handle: avoid\n    label: What must not appear?\nguidance: |\n  Open on the reader.\nchecklist:\n  - Facts come from the brief.\n</type>");

        (new AnalyseCollection('articles'))->handle(app(Studio::class), app(TypeRepository::class), app(TypeState::class));

        $type = app(TypeRepository::class)->find('articles');

        $this->assertSame('Project article', $type->title);
        $this->assertSame('articles', $type->collection);
        $this->assertSame(['what', 'avoid'], array_column($type->questions, 'handle'));
        $this->assertSame(TypeState::IDLE, app(TypeState::class)->get('articles')['status']);

        // The analyst was shown the fields, the pattern and a real entry.
        $this->ai->assertSent('type-analyst', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'in this order: hero, long_form, cards, related')
            && str_contains($prompt->prompt, 'A paragraph about'));
    }

    public function test_an_unreadable_analysis_is_asked_for_again_then_fails_without_saving_anything(): void
    {
        $this->ai->respond('type-analyst', 'Sorry, I cannot help with that.', 'Still no.');

        (new AnalyseCollection('articles'))->handle(app(Studio::class), app(TypeRepository::class), app(TypeState::class));

        $this->assertNull(app(TypeRepository::class)->find('articles'));
        $this->assertSame(TypeState::FAILED, app(TypeState::class)->get('articles')['status']);

        // The second ask said what was wrong with the first answer.
        $this->ai->assertSent('type-analyst', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'could not be read: there was no <type> block'));
    }

    public function test_a_type_in_a_code_fence_or_fixed_on_the_second_try_is_read(): void
    {
        $this->ai->respond('type-analyst',
            "<type>\n```yaml\ntitle: Fenced\nquestions: not a list\n```\n</type>",
            "<type>\n```yaml\ntitle: Fenced\nquestions:\n  - handle: what\n    label: What?\n```\n</type>",
        );

        (new AnalyseCollection('articles'))->handle(app(Studio::class), app(TypeRepository::class), app(TypeState::class));

        $this->assertSame('Fenced', app(TypeRepository::class)->find('articles')->title);
        $this->ai->assertSent('type-analyst', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'could not be read: it had no questions'));
    }

    public function test_kinds_of_content_are_suggested_and_can_be_learned_or_turned_down(): void
    {
        Bus::fake([AnalyseCollection::class]);
        $this->signIn();
        $this->makeType();

        $ids = Entry::query()->where('collection', 'articles')->get()->keyBy->slug()->map->id();

        $this->ai->respond('kind-finder', "<kinds>\n- title: Project write-up\n  description: One project told start to finish.\n  why: Three entries share the hero, long form and cards.\n  examples: [\"{$ids['one']}\", \"{$ids['two']}\", \"nowhere\"]\n- title: Article\n  description: Already taught, so left out.\n  examples: [\"{$ids['one']}\", \"{$ids['two']}\"]\n- title: Lonely\n  description: Only one example.\n  examples: [\"{$ids['three']}\"]\n</kinds>");

        (new SuggestKinds(['articles', 'nowhere']))->handle(app(Studio::class), app(TypeRepository::class), app(KindSuggestions::class));

        $state = app(KindSuggestions::class)->get('articles');

        $this->assertSame(KindSuggestions::IDLE, $state['status']);
        $this->assertSame(3, $state['entries']);
        $this->assertSame(['Project write-up'], array_column($state['suggestions'], 'title'));
        $this->assertSame([$ids['one'], $ids['two']], $state['suggestions'][0]['examples']);
        $this->assertSame('article', $state['suggestions'][0]['blueprint']);

        // The scout was shown each entry: its title, how it is built and how it opens.
        $this->ai->assertSent('kind-finder', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'built as: hero, long_form, cards, related') && str_contains($prompt->prompt, 'opens: "A summary line about One'));

        $kinds = $this->getJson(cp_route('ghostwriter.kinds.show', 'articles'))->assertOk()->json('kinds');
        $suggestion = $kinds['suggestions'][0];

        $this->assertSame(['One', 'Two'], $suggestion['titles']);

        // Learned: the same job as teaching by hand, with the kind's name and entries.
        $this->postJson($suggestion['learn_url'])->assertOk()->assertJsonPath('state.status', TypeState::WORKING)->assertJsonPath('kinds.suggestions', []);

        Bus::assertDispatchedAfterResponse(AnalyseCollection::class, fn ($job) => $job->title === 'Project write-up' && $job->examples === [$ids['one'], $ids['two']]);

        $this->postJson($suggestion['learn_url'])->assertNotFound();
    }

    public function test_the_kinds_poll_says_when_learning_a_kind_has_finished_or_failed(): void
    {
        $this->signIn();
        $this->makeArticlesCollection();

        app(TypeState::class)->set('articles', TypeState::WORKING);
        $this->getJson(cp_route('ghostwriter.kinds.show', 'articles'))->assertOk()->assertJsonPath('state.status', 'working');

        app(TypeState::class)->set('articles', TypeState::FAILED, 'The model gave up.');
        $this->getJson(cp_route('ghostwriter.kinds.show', 'articles'))
            ->assertJsonPath('state.status', 'failed')
            ->assertJsonPath('state.error', 'The model gave up.');

        app(TypeState::class)->set('articles', TypeState::IDLE);
        $this->getJson(cp_route('ghostwriter.kinds.show', 'articles'))->assertJsonPath('state.status', 'idle');
    }

    public function test_a_scout_with_nothing_to_add_is_not_a_failure(): void
    {
        $this->makeType();
        $this->ai->respond('kind-finder', 'Both entries are already covered by the Article kind, so there is nothing to suggest.');

        (new SuggestKinds(['articles']))->handle(app(Studio::class), app(TypeRepository::class), app(KindSuggestions::class));

        $state = app(KindSuggestions::class)->get('articles');

        $this->assertSame(KindSuggestions::IDLE, $state['status']);
        $this->assertSame([], $state['suggestions']);
        $this->assertNotNull($state['checked_at']);
    }

    public function test_a_turned_down_kind_is_not_suggested_again_and_learn_all_queues_the_rest(): void
    {
        Bus::fake([AnalyseCollection::class]);
        $this->signIn();

        $suggestions = app(KindSuggestions::class);
        $suggestions->store('articles', [
            ['title' => 'Press release', 'description' => '', 'why' => '', 'examples' => ['a', 'b'], 'blueprint' => null],
            ['title' => 'Event', 'description' => '', 'why' => '', 'examples' => ['c', 'd'], 'blueprint' => null],
            ['title' => 'Award', 'description' => '', 'why' => '', 'examples' => ['e', 'f'], 'blueprint' => null],
        ], 3);

        $first = $suggestions->get('articles')['suggestions'][0];

        $this->postJson(cp_route('ghostwriter.kinds.dismiss', ['articles', $first['id']]))->assertOk()->assertJsonCount(2, 'kinds.suggestions');
        $this->assertSame(['Press release'], $suggestions->get('articles')['dismissed']);

        // Next time the scout looks, it is told what was turned down.
        $this->ai->respond('kind-finder', "<kinds>\n- title: Press release\n  examples: [\"x\", \"y\"]\n</kinds>");
        app(Studio::class)->suggestKinds(Collection::findByHandle('articles'), app(TypeRepository::class), $suggestions);
        $this->ai->assertSent('kind-finder');

        $this->postJson(cp_route('ghostwriter.kinds.learn_all', 'articles'))->assertOk()->assertJsonPath('kinds.suggestions', []);

        Bus::assertDispatchedAfterResponse(AnalyseCollection::class, fn ($job) => array_column($job->kinds, 'title') === ['Event', 'Award']);
    }

    public function test_kinds_can_be_suggested_for_every_collection_at_once(): void
    {
        Bus::fake([SuggestKinds::class]);
        $this->signIn();
        $this->makePostsCollection();

        // One already being looked at is left to finish.
        app(KindSuggestions::class)->update('posts', ['status' => KindSuggestions::WORKING, 'error' => null]);

        $this->postJson(cp_route('ghostwriter.kinds.suggest_all'))
            ->assertOk()
            ->assertJsonPath('collections.articles.kinds.status', KindSuggestions::WORKING)
            ->assertJsonPath('collections.posts.kinds.status', KindSuggestions::WORKING);

        Bus::assertDispatchedAfterResponse(SuggestKinds::class, fn (SuggestKinds $job) => $job->collections === ['articles']);

        $this->withoutKeys('anthropic');
        $this->postJson(cp_route('ghostwriter.kinds.suggest_all'))->assertStatus(422);
    }

    public function test_learning_several_kinds_carries_on_past_one_that_fails(): void
    {
        $this->ai->respond('type-analyst',
            "<type>\ntitle: Event\ndescription: An event.\nquestions:\n  - handle: when\n    label: When?\n</type>",
            'Nonsense.',
            'Nonsense again.',
        );

        (new AnalyseCollection('articles', kinds: [['title' => 'Event', 'examples' => []], ['title' => 'Award', 'examples' => []]]))
            ->handle(app(Studio::class), app(TypeRepository::class), app(TypeState::class));

        $this->assertSame('Event', app(TypeRepository::class)->find('event')->title);
        $this->assertNull(app(TypeRepository::class)->find('award'));
        $this->assertSame(TypeState::FAILED, app(TypeState::class)->get('articles')['status']);
        $this->assertStringStartsWith('Award: ', app(TypeState::class)->get('articles')['error']);

        // A second type with the same title gets its own handle.
        $this->assertSame('event-2', app(TypeRepository::class)->handleFor('Event', 'articles'));
    }

    public function test_the_dashboard_checks_a_collection_for_kinds_the_first_time_and_after_ten_more_entries(): void
    {
        Bus::fake([SuggestKinds::class]);
        $this->signIn();

        $this->get(cp_route('ghostwriter.index'))->assertOk();
        Bus::assertDispatchedAfterResponse(SuggestKinds::class, fn ($job) => $job->collections === ['articles']);
        $this->assertSame(KindSuggestions::WORKING, app(KindSuggestions::class)->get('articles')['status']);

        // Checked, with three entries: not again until ten more are published.
        app(KindSuggestions::class)->store('articles', [], 3);
        Bus::fake([SuggestKinds::class]);
        $this->get(cp_route('ghostwriter.index'))->assertOk();
        Bus::assertNotDispatchedAfterResponse(SuggestKinds::class);

        foreach (range(4, 13) as $n) {
            $this->makeArticle("more-{$n}", "More {$n}", 'Another paragraph long enough to be read as a sample of writing.');
        }

        $this->get(cp_route('ghostwriter.index'))->assertOk();
        Bus::assertDispatchedAfterResponse(SuggestKinds::class);

        // Switched off, nothing happens by itself.
        config(['ghostwriter.suggest_kinds' => false]);
        app(KindSuggestions::class)->update('articles', ['status' => KindSuggestions::IDLE, 'checked_at' => null]);
        Bus::fake([SuggestKinds::class]);
        $this->get(cp_route('ghostwriter.index'))->assertOk();
        Bus::assertNotDispatchedAfterResponse(SuggestKinds::class);
    }

    public function test_the_panel_is_told_about_its_collection(): void
    {
        Bus::fake([AnalyseCollection::class]);
        $this->signIn();

        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))
            ->assertOk()
            ->assertJsonPath('collection.title', 'Articles')
            ->assertJsonCount(1, 'types')
            ->assertJsonPath('types.0.generic', true)
            ->assertJsonPath('kinds', [])
            ->assertJsonPath('entries.0.depth', 0)
            ->assertJsonPath('has_voice', false);

        $this->postJson(cp_route('ghostwriter.collections.analyse', 'articles'))
            ->assertOk()
            ->assertJsonPath('state.status', TypeState::WORKING);

        Bus::assertDispatchedAfterResponse(AnalyseCollection::class, fn ($job) => $job->collection === 'articles');

        $this->makeType();

        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))
            ->assertJsonPath('types.0.title', 'Article')
            ->assertJsonPath('types.0.questions.0.handle', 'what')
            ->assertJsonPath('types.1.generic', true);
    }

    public function test_any_collection_can_be_written_for_without_being_learned(): void
    {
        Bus::fake([RunSessionTurn::class]);
        $this->signIn();
        $this->makePostsCollection();
        Entry::make()->collection('posts')->slug('elsewhere')->data(['title' => 'Elsewhere'])->save();

        $one = Entry::query()->where('slug', 'one')->first()->id();
        $elsewhere = Entry::query()->where('slug', 'elsewhere')->first()->id();

        $answers = ['subject' => 'Our new office.', 'reader' => 'Clients.', 'points' => 'We moved in May.'];

        $this->postJson(cp_route('ghostwriter.sessions.store', 'any:articles'), ['answers' => ['subject' => 'Our new office.']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers.reader', 'answers.points']);

        $id = $this->postJson(cp_route('ghostwriter.sessions.store', 'any:articles'), ['answers' => $answers, 'examples' => [$one, $elsewhere]])
            ->assertOk()
            ->json('id');

        // Entries from another collection cannot be the model.
        $session = app(SessionRepository::class)->find($id);
        $this->assertSame([$one], $session->examples);
        $this->assertStringContainsString('We moved in May.', $session->messages[0]['content']);

        // The general brief has nothing to edit.
        $this->get(cp_route('ghostwriter.types.edit', 'any:articles'))->assertNotFound();
        $this->postJson(cp_route('ghostwriter.sessions.store', 'any:nowhere'), ['answers' => $answers])->assertNotFound();
    }

    public function test_a_piece_is_modelled_on_the_entries_picked_for_it(): void
    {
        $this->makeArticle('landing', 'Landing', 'A landing page paragraph that is long enough to be read as a real sample.', ['page_builder' => [
            ['id' => 'l1', 'type' => 'hero', 'enabled' => true, 'heading' => 'We build things'],
            ['id' => 'l2', 'type' => 'gallery', 'enabled' => true, 'caption' => 'Our work'],
        ]]);

        $landing = Entry::query()->where('slug', 'landing')->first()->id();
        $type = app(TypeRepository::class)->find('any:articles');

        $general = app(Studio::class)->writerInstructions($type, '');
        $modelled = app(Studio::class)->writerInstructions($type->modelledOn([$landing]), '');

        $this->assertStringContainsString('A paragraph about', $general);
        $this->assertStringContainsString('We build things', $modelled);
        $this->assertStringNotContainsString('A paragraph about', $modelled);
    }

    public function test_kinds_of_entry_are_found_from_how_entries_are_built(): void
    {
        $this->signIn();

        foreach (['landing-a' => 'Landing A', 'landing-b' => 'Landing B'] as $slug => $title) {
            $this->makeArticle($slug, $title, 'A landing page paragraph.', ['page_builder' => [
                ['id' => 'l1', 'type' => 'hero', 'enabled' => true, 'heading' => $title],
                ['id' => 'l2', 'type' => 'gallery', 'enabled' => true, 'caption' => 'Our work'],
            ]]);
        }

        // Built like nothing else, so it is not a kind.
        $this->makeArticle('one-off', 'One-off', 'A paragraph.', ['page_builder' => [['id' => 'o1', 'type' => 'related', 'enabled' => true]]]);

        $kinds = $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertOk()->json('kinds');

        $this->assertCount(2, $kinds);
        $this->assertSame([3, 2], array_column($kinds, 'count'));
        $this->assertSame(['hero', 'gallery'], $kinds[1]['blocks']);
        $this->assertEqualsCanonicalizing(['Landing A', 'Landing B'], $kinds[1]['titles']);
        $this->assertStringStartsWith('Like Landing', $kinds[1]['label']);
        $this->assertCount(2, $kinds[1]['examples']);
    }

    public function test_a_collection_built_one_way_offers_no_kinds(): void
    {
        $this->signIn();
        $this->makePostsCollection();
        Entry::make()->collection('posts')->slug('a')->published(true)->data(['title' => 'A'])->save();
        Entry::make()->collection('posts')->slug('b')->published(true)->data(['title' => 'B'])->save();

        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertJsonPath('kinds', []);
        $this->getJson(cp_route('ghostwriter.collections.show', 'posts'))->assertJsonPath('kinds', []);
    }

    public function test_a_type_can_be_modelled_on_chosen_entries(): void
    {
        Bus::fake([AnalyseCollection::class]);
        $this->signIn();
        $this->makePostsCollection();

        // One entry built differently from the rest, and one from elsewhere.
        $this->makeArticle('landing', 'Landing', 'A landing page paragraph that is long enough to be read as a real sample.', ['page_builder' => [
            ['id' => 'l1', 'type' => 'hero', 'enabled' => true, 'heading' => 'We build things'],
            ['id' => 'l2', 'type' => 'gallery', 'enabled' => true, 'caption' => 'Our work'],
        ]]);
        Entry::make()->collection('posts')->slug('elsewhere')->data(['title' => 'Elsewhere'])->save();

        $landing = Entry::query()->where('slug', 'landing')->first()->id();
        $elsewhere = Entry::query()->where('slug', 'elsewhere')->first()->id();

        $this->postJson(cp_route('ghostwriter.collections.analyse', 'articles'), ['title' => 'Landing page', 'examples' => [$landing, $elsewhere]])
            ->assertOk()
            ->assertJsonPath('entries.0.title', fn ($title) => is_string($title));

        // Entries from another collection are dropped.
        Bus::assertDispatchedAfterResponse(AnalyseCollection::class, fn ($job) => $job->title === 'Landing page' && $job->examples === [$landing]);

        $this->ai->respond('type-analyst', "<type>\ntitle: Ignored\ndescription: A landing page.\nquestions:\n  - handle: what\n    label: What is it for?\nguidance: Short.\n</type>");

        (new AnalyseCollection('articles', 'Landing page', [$landing]))->handle(app(Studio::class), app(TypeRepository::class), app(TypeState::class));

        $type = app(TypeRepository::class)->find('landing-page');

        $this->assertSame('Landing page', $type->title);
        $this->assertSame([$landing], $type->examples);
        $this->assertSame('article', $type->blueprint);

        // The writer is shown that entry's pattern, not the collection's.
        $instructions = app(Studio::class)->writerInstructions($type, '');
        $this->assertStringContainsString('in this order: hero, gallery', $instructions);
        $this->assertStringContainsString('We build things', $instructions);

        $this->ai->assertSent('type-analyst', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'chosen by an editor') && str_contains($prompt->prompt, '"Landing page"'));
    }

    public function test_a_type_is_edited_on_a_publish_form_and_can_be_deleted(): void
    {
        $this->signIn();
        $this->makeType();

        $this->get(cp_route('ghostwriter.types.edit', 'articles'))->assertOk();

        $this->patchJson(cp_route('ghostwriter.types.update', 'articles'), [
            'title' => 'Project article',
            'description' => 'Rewritten.',
            'questions' => [
                ['label' => 'Who was it for?', 'handle' => 'who', 'type' => 'text', 'required' => true, 'instructions' => 'A description will do.'],
            ],
            'guidance' => "Open on the reader.\n\nThen the project.",
            'checklist' => ['No invented figures.'],
            'examples' => [],
        ])->assertOk();

        $type = app(TypeRepository::class)->find('articles');

        $this->assertSame('Project article', $type->title);
        $this->assertSame('articles', $type->collection);
        $this->assertSame([['handle' => 'who', 'label' => 'Who was it for?', 'instructions' => 'A description will do.', 'type' => 'text', 'required' => true]], $type->questions);
        $this->assertSame(['No invented figures.'], $type->checklist);

        $this->patchJson(cp_route('ghostwriter.types.update', 'articles'), ['title' => ''])->assertStatus(422);

        // A kind needs a question: without one it is the general brief.
        $this->patchJson(cp_route('ghostwriter.types.update', 'articles'), ['title' => 'Project article', 'questions' => []])
            ->assertStatus(422)
            ->assertJsonPath('errors.questions.0', 'A kind of content needs at least one question.');

        // Handles are not shown: a question keeps the one it had, and a new
        // one gets one made from its wording, never a duplicate.
        $this->patchJson(cp_route('ghostwriter.types.update', 'articles'), [
            'title' => 'Project article',
            'questions' => [
                ['label' => 'Who was it for, really?', 'handle' => 'who', 'type' => 'text'],
                ['label' => 'What did it cost?', 'handle' => null, 'type' => 'textarea'],
                ['label' => 'Who', 'type' => 'text'],
            ],
        ])->assertOk();

        $this->assertSame(['who', 'what_did_it_cost', 'who_2'], array_column(app(TypeRepository::class)->find('articles')->questions, 'handle'));

        $this->deleteJson(cp_route('ghostwriter.types.destroy', 'articles'))->assertOk();
        $this->assertNull(app(TypeRepository::class)->find('articles'));
    }

    public function test_only_configured_collections_are_offered(): void
    {
        $this->makePostsCollection();
        config(['ghostwriter.collections' => ['posts']]);
        $this->signIn();

        $this->getJson(cp_route('ghostwriter.collections.show', 'posts'))->assertOk();
        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertNotFound();

        $action = new WriteWithGhostwriter;

        $this->assertTrue($action->visibleTo(Collection::findByHandle('posts')));
        $this->assertFalse($action->visibleTo(Collection::findByHandle('articles')));
        $this->assertFalse($action->visibleTo(Entry::query()->first()));
        $this->assertStringEndsWith('?ghostwriter=new', $action->redirect(collect([Collection::findByHandle('posts')]), []));
    }

    public function test_a_title_and_notes_are_expanded_into_a_brief_to_check(): void
    {
        $this->signIn();
        $this->makeType();

        $this->ai->respond('brief-writer', "<brief>\nwhat: |\n  A search that narrows 400 products by what the customer needs.\n  [Add: the client and what changed after launch]\navoid: Client names.\nmade_up: ignored\n</brief>");

        $this->postJson(cp_route('ghostwriter.types.brief', 'articles'), ['notes' => 'No title.'])->assertStatus(422);

        $this->postJson(cp_route('ghostwriter.types.brief', 'articles'), ['title' => 'Faceted search', 'notes' => 'For a kitchen appliance maker.'])
            ->assertOk()
            ->assertExactJson(['answers' => [
                'what' => "A search that narrows 400 products by what the customer needs.\n[Add: the client and what changed after launch]",
                'avoid' => 'Client names.',
            ]]);

        // It was given the title, the notes, the questions and what is already there.
        $this->ai->assertSent('brief-writer', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'Working title: Faceted search') && str_contains($prompt->prompt, 'For a kitchen appliance maker.'));

        // Nothing has been started.
        $this->assertCount(0, app(SessionRepository::class)->all());

        $this->ai->reset('brief-writer')->respond('brief-writer', 'I would rather chat about it.');

        $this->postJson(cp_route('ghostwriter.types.brief', 'articles'), ['title' => 'Faceted search'])->assertStatus(422);
    }

    public function test_the_questionnaire_requires_its_required_answers(): void
    {
        Bus::fake([RunSessionTurn::class]);
        $this->signIn();
        $this->makeType();

        $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'), ['answers' => ['avoid' => 'Client names.']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers.what']);

        Bus::assertNotDispatchedAfterResponse(RunSessionTurn::class);
    }

    public function test_submitting_the_questionnaire_starts_a_session_with_the_brief(): void
    {
        Bus::fake([RunSessionTurn::class]);
        $user = $this->signIn();
        $this->makeType();

        $id = $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'), ['answers' => ['what' => 'A faceted search.']])
            ->assertOk()
            ->assertJsonPath('status', Session::WORKING)
            ->json('id');

        $session = app(SessionRepository::class)->find($id);

        $this->assertSame($user->id(), $session->userId);
        $this->assertStringContainsString('A faceted search.', $session->messages[0]['content']);
        $this->assertStringContainsString('(not answered)', $session->messages[0]['content']);

        Bus::assertDispatchedAfterResponse(RunSessionTurn::class, fn ($job) => $job->sessionId === $id);
    }

    public function test_the_writer_can_interview_first_and_draft_second(): void
    {
        app(VoiceGuide::class)->save("# Tone of voice\n\nTwo punchlines at most.");

        $type = $this->makeType();
        $session = $this->startedSession();

        $this->ai->respond('writer',
            '<reply>1. Which projects can I cite?</reply>',
            "<reply>Here is the draft.</reply>\n<draft>\n".self::DRAFT."\n</draft>",
        );

        $this->runTurn($session);

        $session = app(SessionRepository::class)->find($session->id);
        $this->assertNull($session->draft);
        $this->assertSame('1. Which projects can I cite?', end($session->messages)['content']);

        // The panel is told the writer is waiting for an answer.
        $this->assertTrue(app(Presenter::class)->detail($session)['waiting_on_you']);

        $session->addMessage('user', 'The pub and the fitness app.');
        app(SessionRepository::class)->save($session);

        $this->runTurn($session);

        $session = app(SessionRepository::class)->find($session->id);
        $this->assertSame(self::DRAFT, $session->draft);
        $this->assertFalse(app(Presenter::class)->detail($session)['waiting_on_you']);

        // The conversation records that this turn wrote the draft; the turn
        // that only asked a question recorded nothing.
        $this->assertSame('written', end($session->messages)['draft']['change']);
        $this->assertSame(str_word_count(self::DRAFT), end($session->messages)['draft']['words']);
        $this->assertArrayNotHasKey('draft', $session->messages[1]);
        $this->assertSame('What Does a Website Cost?', $session->title());
        $this->assertSame(Session::IDLE, $session->status);

        // The writer's instructions carry the voice, the type's guidance, the
        // fields read from the blueprint, the pattern and a real example.
        $instructions = app(Studio::class)->writerInstructions($type, app(VoiceGuide::class)->get());

        foreach (['Two punchlines at most.', 'Open on the reader. Two sections.', '`long_form`: Long Form', 'in this order: hero, long_form, cards, related', '<example number="1">', 'A paragraph about'] as $expected) {
            $this->assertStringContainsString($expected, $instructions);
        }

        $this->ai->assertSent('writer', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'The pub and the fitness app.'));
    }

    public function test_a_revision_is_sent_the_current_draft(): void
    {
        $this->makeType();
        $session = $this->startedSession();
        $session->draft = self::DRAFT;
        $session->addMessage('assistant', 'Here is the draft.');
        $session->addMessage('user', 'Shorten the opening.');
        app(SessionRepository::class)->save($session);

        $this->ai->respond('writer', '<reply>Shortened.</reply><draft>'.str_replace('Because they are for **different** websites.', 'Different websites.', self::DRAFT).'</draft>');

        $this->runTurn($session);

        $this->assertStringContainsString('Different websites.', app(SessionRepository::class)->find($session->id)->draft);

        $this->ai->assertSent('writer', fn (TextRequest $prompt) => str_contains($prompt->prompt, '<current_draft>') && str_contains($prompt->prompt, 'Shorten the opening.'));
    }

    public function test_a_failed_call_marks_the_session_failed_and_keeps_the_draft(): void
    {
        $this->makeType();
        $session = $this->startedSession();
        $session->draft = self::DRAFT;
        app(SessionRepository::class)->save($session);

        $this->ai->respond('writer', fn () => throw new \RuntimeException('The provider is overloaded.'));

        $this->runTurn($session);

        $session = app(SessionRepository::class)->find($session->id);
        $this->assertSame(Session::FAILED, $session->status);
        $this->assertSame('The provider is overloaded.', $session->error);
        $this->assertSame(self::DRAFT, $session->draft);
    }

    public function test_a_failed_turn_can_be_tried_again_with_the_same_message(): void
    {
        Bus::fake([RunSessionTurn::class]);
        $this->signIn();
        $this->makeType();

        $session = $this->startedSession();

        // Nothing has failed: nothing to try again.
        $this->postJson(cp_route('ghostwriter.sessions.retry', $session->id))->assertStatus(409);

        $this->ai->respond('writer', fn () => throw new \RuntimeException('The provider is overloaded.'));
        $this->runTurn($session);

        $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))
            ->assertJsonPath('status', Session::FAILED)
            ->assertJsonPath('error', 'The provider is overloaded.');

        $this->postJson(cp_route('ghostwriter.sessions.retry', $session->id))
            ->assertOk()
            ->assertJsonPath('status', Session::WORKING)
            ->assertJsonPath('error', null);

        Bus::assertDispatchedAfterResponse(RunSessionTurn::class, fn (RunSessionTurn $job) => $job->sessionId === $session->id);

        // The same message, not a second copy of it.
        $this->assertCount(1, app(SessionRepository::class)->find($session->id)->messages);
    }

    public function test_a_turn_no_worker_has_picked_up_says_so_after_thirty_seconds(): void
    {
        Bus::fake([RunSessionTurn::class]);
        $this->signIn();
        $this->makeType();

        $session = $this->startedSession();
        $session->status = Session::IDLE;
        app(SessionRepository::class)->save($session);

        // A real queue, with no worker.
        Queue::fake();
        config(['queue.default' => 'database', 'queue.connections.database.queue' => 'default']);

        $this->postJson(cp_route('ghostwriter.sessions.message', $session->id), ['message' => 'Shorten it.'])
            ->assertOk()
            ->assertJsonPath('queue_waiting', null);

        $this->travel(31)->seconds();

        $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))
            ->assertJsonPath('queue_waiting', 'Still waiting for a queue worker to pick this up. Is `php artisan queue:work` running?');

        // A worker starts it: nothing more to say.
        $this->ai->respond('writer', '<reply>Shorter.</reply>');
        $this->runTurn(app(SessionRepository::class)->find($session->id));

        $this->assertNull(app(Waiting::class)->waited('session:'.$session->id));

        // On the sync queue the work runs itself; there is never a worker to wait for.
        config(['queue.default' => 'sync']);
        app(Waiting::class)->queued('session:'.$session->id);
        $this->travel(60)->seconds();
        $this->assertNull(app(Waiting::class)->notice('session:'.$session->id));
    }

    public function test_a_draft_cut_off_twice_is_not_kept(): void
    {
        $this->makeType();
        $session = $this->startedSession();
        $session->draft = self::DRAFT;
        app(SessionRepository::class)->save($session);

        $this->ai->respond('writer', new TextResponse("<reply>Here.</reply>\n<draft>\ntitle: Half", StopReason::MaxTokens));

        $this->runTurn($session);

        // Asked once at the writer's limit, then once with twice the room.
        $this->assertSame([16000, 32000], array_map(fn (TextRequest $request) => $request->resolvedMaxTokens(), $this->ai->prompted('writer')));

        $session = app(SessionRepository::class)->find($session->id);
        $this->assertSame(Session::FAILED, $session->status);
        $this->assertStringContainsString('was cut off', $session->error);
        $this->assertSame(self::DRAFT, $session->draft);
    }

    public function test_a_draft_cut_off_once_is_asked_for_again_with_more_room(): void
    {
        $this->makeType();
        $session = $this->startedSession();

        $this->ai->respond('writer',
            new TextResponse("<reply>Here.</reply>\n<draft>\ntitle: Half", StopReason::MaxTokens),
            "<reply>Here is the draft.</reply>\n<draft>\n".self::DRAFT."\n</draft>",
        );

        $this->runTurn($session);

        $this->assertCount(2, $this->ai->prompted('writer'));
        $this->assertSame(Session::IDLE, app(SessionRepository::class)->find($session->id)->status);
        $this->assertStringContainsString('Because they are for **different** websites.', app(SessionRepository::class)->find($session->id)->draft);
    }

    public function test_a_message_cannot_be_sent_while_the_last_one_is_being_answered(): void
    {
        Bus::fake([RunSessionTurn::class]);
        $this->signIn();
        $this->makeType();

        $session = $this->startedSession();

        $this->postJson(cp_route('ghostwriter.sessions.message', $session->id), ['message' => 'Hello?'])->assertStatus(409);

        $session->status = Session::IDLE;
        app(SessionRepository::class)->save($session);

        $this->postJson(cp_route('ghostwriter.sessions.message', $session->id), ['message' => 'Shorten it.'])
            ->assertOk()
            ->assertJsonPath('status', Session::WORKING);

        Bus::assertDispatchedAfterResponse(RunSessionTurn::class);
    }

    public function test_the_draft_is_laid_out_for_reading(): void
    {
        $this->signIn();
        $this->makeType();

        $session = $this->sessionWithDraft(self::DRAFT."\n  - type: carousel");

        $preview = $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))->assertOk()->json('preview');

        $this->assertSame(['title', 'summary', 'page_builder'], array_column($preview, 'handle'));

        $blocks = $preview[2]['items'];
        $this->assertSame(['Hero', 'Long Form', 'Related', 'carousel'], array_column($blocks, 'label'));
        $this->assertSame([true, true, true, false], array_column($blocks, 'known'));
        $this->assertStringContainsString('<strong>different</strong>', $blocks[1]['fields'][0]['html']);
    }

    public function test_the_preview_escapes_html_the_model_wrote(): void
    {
        $this->signIn();
        $this->makeType();

        $session = $this->sessionWithDraft("title: Safe\npage_builder:\n  - type: long_form\n    content: |\n      <script>alert(1)</script> and [a link](javascript:alert(1)).");

        $html = $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))->json('preview.1.items.0.fields.0.html');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    public function test_applying_a_draft_returns_values_the_publish_form_can_take(): void
    {
        $this->signIn();
        $this->makeType();

        $session = $this->sessionWithDraft(self::DRAFT."\n  - type: carousel");

        $response = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id))->assertOk();

        $values = $response->json('values');

        $this->assertSame('What Does a Website Cost?', $values['title']);
        $this->assertSame(['hero', 'long_form', 'related'], array_column($values['page_builder'], 'type'));
        $this->assertSame('user-1', $values['author'][0] ?? $values['author']);

        // Pre-processed the way a saved entry would be: each set carries the
        // _id the replicator keys its meta by, and the related block has its
        // usual heading.
        $ids = array_column($values['page_builder'], '_id');
        $this->assertCount(3, array_filter($ids));
        $this->assertSame('More articles', $values['page_builder'][2]['heading']);
        $this->assertEqualsCanonicalizing($ids, array_keys($response->json('meta.page_builder.existing')));

        // Fields the draft did not touch are not sent, so the form keeps them.
        $this->assertArrayNotHasKey('featured_image', $values);
        $this->assertStringContainsString('"carousel" does not exist', $response->json('notes.0'));

        // Nothing was saved.
        $this->assertSame(3, Entry::query()->where('collection', 'articles')->count());
    }

    public function test_a_draft_can_also_be_saved_straight_to_an_unpublished_entry(): void
    {
        $user = $this->signIn();
        $this->makeType();

        $session = $this->sessionWithDraft(self::DRAFT);

        $this->postJson(cp_route('ghostwriter.sessions.entry', $session->id))
            ->assertOk()
            ->assertJsonPath('entry_url', fn ($url) => is_string($url) && $url !== '');

        $entry = Entry::query()->where('collection', 'articles')->where('slug', 'what-does-a-website-cost')->first();

        $this->assertFalse($entry->published());
        $this->assertSame('Why quotes vary, and what moves the number.', $entry->get('summary'));
        $this->assertSame(['hero', 'long_form', 'related'], array_column($entry->get('page_builder'), 'type'));
        $this->assertSame($entry->id(), app(SessionRepository::class)->find($session->id)->entryId);

        // A second entry from the same draft does not overwrite the first.
        $this->postJson(cp_route('ghostwriter.sessions.entry', $session->id))->assertOk();
        $this->assertSame(1, Entry::query()->where('collection', 'articles')->where('slug', 'what-does-a-website-cost-2')->count());
    }

    public function test_a_session_belongs_to_whoever_started_it(): void
    {
        Bus::fake([SuggestKinds::class]);
        $this->signIn();
        $this->makeType();

        $theirs = $this->sessionWithDraft(self::DRAFT);
        $theirs->userId = 'someone-else';
        app(SessionRepository::class)->save($theirs);

        // Nobody else sees it listed, or can open it, read it, write in it,
        // use its draft or remove it.
        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertJsonPath('sessions', []);
        $this->get(cp_route('ghostwriter.index'))->assertOk()->assertInertia(fn ($page) => $page->where('counts.in_progress', 0));
        $this->assertSame([], (new Ghostwriter)->component()->toArray()['props']['inProgress']);

        $this->getJson(cp_route('ghostwriter.sessions.show', $theirs->id))->assertForbidden();
        $this->get(cp_route('ghostwriter.sessions.open', $theirs->id))->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.message', $theirs->id), ['content' => 'Shorter.'])->assertForbidden();
        $this->patchJson(cp_route('ghostwriter.sessions.field', $theirs->id), ['path' => 'title', 'value' => 'Mine now'])->assertForbidden();
        $this->patchJson(cp_route('ghostwriter.sessions.draft', $theirs->id), ['draft' => 'title: Mine'])->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.apply', $theirs->id))->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.image', $theirs->id), ['key' => 'x'])->assertForbidden();
        $this->getJson(cp_route('ghostwriter.sessions.photos', $theirs->id, ['key' => 'x']))->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.photo', $theirs->id), ['key' => 'x', 'source' => 'unsplash', 'id' => '1'])->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.image.copy', $theirs->id), ['key' => 'x'])->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.entry', $theirs->id))->assertForbidden();
        $this->deleteJson(cp_route('ghostwriter.sessions.destroy', $theirs->id))->assertForbidden();

        $this->assertNotNull(app(SessionRepository::class)->find($theirs->id));
        $this->assertSame(0, Entry::query()->where('collection', 'articles')->where('slug', 'what-does-a-website-cost')->count());

        // The owner, and a super user, carry on as before.
        $mine = $this->sessionWithDraft(self::DRAFT);
        $mine->userId = (string) User::current()->id();
        app(SessionRepository::class)->save($mine);

        $this->getJson(cp_route('ghostwriter.sessions.show', $mine->id))->assertOk();
        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertJsonCount(1, 'sessions');

        // Solo allows one user, so the same person is made super.
        User::current()->makeSuper()->save();
        $this->getJson(cp_route('ghostwriter.sessions.show', $theirs->id))->assertOk();
        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertJsonCount(2, 'sessions');
    }

    public function test_a_draft_is_not_saved_where_the_person_could_not_create_an_entry(): void
    {
        $this->signInWith(['access ghostwriter', 'view articles entries', 'edit articles entries', 'edit other authors articles entries']);
        $this->makeType();

        $session = $this->sessionWithDraft(self::DRAFT);

        $this->postJson(cp_route('ghostwriter.sessions.entry', $session->id))->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id))->assertForbidden();

        $this->assertSame(0, Entry::query()->where('collection', 'articles')->where('slug', 'what-does-a-website-cost')->count());
        $this->assertNull(app(SessionRepository::class)->find($session->id)->entryId);

        // Editing an entry they may edit is still allowed.
        $this->makeArticle('older', 'Older', 'An older piece.');
        $session->source = Entry::query()->where('slug', 'older')->first()->id();
        app(SessionRepository::class)->save($session);

        $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id))->assertOk();
    }

    public function test_a_session_is_finished_once_its_entry_exists(): void
    {
        $this->signIn();
        $this->makeType();

        $session = $this->sessionWithDraft(self::DRAFT);
        $stage = fn () => array_intersect_key(app(Presenter::class)->summary(app(SessionRepository::class)->find($session->id)), ['stage' => 1, 'finished' => 1]);

        $this->assertSame(['stage' => 'draft', 'finished' => false], $stage());

        // Put into the form: handed over, but nothing is saved yet.
        $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id))->assertOk();
        $this->assertSame(['stage' => 'in_form', 'finished' => false], $stage());

        // The person saves the form. Ghostwriter is not told, and finds the entry by its title.
        $entry = Entry::make()->collection('articles')->slug('cost')->published(false)->data(['title' => 'What does a website cost?']);
        $entry->save();
        $this->assertSame(['stage' => 'saved', 'finished' => true], $stage());

        $entry->published(true)->save();
        $this->assertSame(['stage' => 'published', 'finished' => true], $stage());

        // Finished pieces are not offered to carry on with.
        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertJsonPath('sessions', []);
    }

    public function test_replies_are_rendered_as_markdown_with_html_escaped(): void
    {
        $this->signIn();
        $this->makeType();

        $session = $this->sessionWithDraft(self::DRAFT);
        $session->addMessage('assistant', "Two things:\n\n- **Shorter** opening\n- <script>alert(1)</script> gone");
        app(SessionRepository::class)->save($session);

        $messages = $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))->json('messages');
        $reply = end($messages);

        $this->assertStringContainsString('<li><strong>Shorter</strong> opening</li>', $reply['html']);
        $this->assertStringContainsString('&lt;script&gt;', $reply['html']);
        $this->assertStringNotContainsString('<script>', $reply['html']);
        // The person's own words are left as they are.
        $this->assertArrayNotHasKey('html', $messages[0]);
    }

    public function test_a_piece_is_finished_only_once_its_entry_is_saved(): void
    {
        $this->signIn();
        $this->makeType();

        // An older entry that happens to share the title is not this piece.
        Entry::make()->collection('articles')->slug('old-cost')->published(true)->data(['title' => 'What does a website cost?', 'updated_at' => now()->subDay()->timestamp])->save();

        $session = $this->sessionWithDraft(self::DRAFT);
        $summary = fn (string $id) => array_intersect_key(app(Presenter::class)->summary(app(SessionRepository::class)->find($id)), ['stage' => 1, 'finished' => 1]);

        $this->assertSame(['stage' => 'draft', 'finished' => false], $summary($session->id));

        // Changes to an existing entry: put into its form is not saved.
        Entry::make()->collection('articles')->slug('existing')->published(true)->data(['title' => 'Existing Piece', 'summary' => 'Old.', 'updated_at' => now()->subHour()->timestamp])->save();
        $entry = Entry::query()->where('slug', 'existing')->first();
        $editing = $this->postJson(cp_route('ghostwriter.entries.session', $entry->id()))->json('id');

        $edit = app(SessionRepository::class)->find($editing);
        $edit->appliedAt = now()->toIso8601String();
        app(SessionRepository::class)->save($edit);

        $this->assertSame(['stage' => 'changed', 'finished' => false], $summary($editing));

        // The form is saved: now it is done.
        $entry->set('updated_at', now()->addSecond()->timestamp)->save();
        $this->assertSame(['stage' => 'published', 'finished' => true], $summary($editing));
    }

    public function test_writing_can_be_changed_where_it_is_shown(): void
    {
        $this->signIn();
        $this->makeType();

        $session = $this->sessionWithDraft(self::DRAFT);
        $preview = $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))->json('preview');

        // Each piece of writing knows where it sits in the draft.
        $this->assertSame(['title'], $preview[0]['path']);
        $this->assertTrue($preview[0]['editable']);
        $long = $preview[2]['items'][1]['fields'][0];
        $this->assertSame(['page_builder', 1, 'content'], $long['path']);
        $this->assertSame('html', $long['kind']);
        $this->assertTrue($long['multiline']);

        $this->patchJson(cp_route('ghostwriter.sessions.field', $session->id), ['path' => ['title'], 'value' => " What Does a Site Cost?\r\n", 'format' => 'text'])
            ->assertOk()
            ->assertJsonPath('title', 'What Does a Site Cost?');

        // Rich text comes back as HTML and goes in as markdown.
        $this->patchJson(cp_route('ghostwriter.sessions.field', $session->id), ['path' => ['page_builder', '1', 'content'], 'value' => '<h2>Why so far apart?</h2><p>Because they are for <em>different</em> sites.</p>', 'format' => 'html'])
            ->assertOk();

        $draft = app(SessionRepository::class)->find($session->id)->draft;

        $this->assertStringContainsString('## Why so far apart?', $draft);
        $this->assertStringContainsString('Because they are for *different* sites.', $draft);
        $this->assertStringNotContainsString('<em>', $draft);
        $this->assertStringContainsString('type: related', $draft);

        // Only writing: a block is edited in YAML, and a path that is not there is refused.
        $this->patchJson(cp_route('ghostwriter.sessions.field', $session->id), ['path' => ['page_builder', '1'], 'value' => 'x'])->assertStatus(422);
        $this->patchJson(cp_route('ghostwriter.sessions.field', $session->id), ['path' => ['nowhere'], 'value' => 'x'])->assertStatus(422);
    }

    public function test_an_edited_entry_keeps_pending_changes_until_asked_to_start_again(): void
    {
        $this->signIn();

        Entry::make()->collection('articles')->slug('existing')->published(true)->data(['title' => 'Existing Piece', 'summary' => 'The old summary.'])->save();
        $entry = Entry::query()->where('slug', 'existing')->first();

        $id = $this->postJson(cp_route('ghostwriter.entries.session', $entry->id()))->assertOk()->json('id');

        // A change asked for and written, but not yet put into the entry.
        $session = app(SessionRepository::class)->find($id);
        $session->addMessage('user', 'Shorten the summary.');
        $session->addMessage('assistant', 'Done.');
        $session->draft = "title: Existing Piece\nsummary: Shorter.";
        app(SessionRepository::class)->save($session);

        // Reopened: the changes are still there.
        $this->postJson(cp_route('ghostwriter.entries.session', $entry->id()))->assertJsonPath('id', $id)->assertJsonPath('draft', "title: Existing Piece\nsummary: Shorter.");

        // Started again: back to the entry as saved, in the same conversation.
        $detail = $this->postJson(cp_route('ghostwriter.entries.session', $entry->id()), ['fresh' => true])->assertJsonPath('id', $id)->json();

        $this->assertStringContainsString('The old summary.', $detail['draft']);
        $this->assertStringContainsString('Start again from the entry', end($detail['messages'])['content'] === 'I have the entry as it stands. Tell me what to change.' ? $detail['messages'][count($detail['messages']) - 2]['content'] : '');

        // Once the changes have been put into the entry, reopening starts afresh too.
        $session = app(SessionRepository::class)->find($id);
        $session->draft = "title: Existing Piece\nsummary: Shorter still.";
        $session->appliedAt = now()->toIso8601String();
        app(SessionRepository::class)->save($session);

        $this->postJson(cp_route('ghostwriter.entries.session', $entry->id()))->assertJsonPath('id', $id)->assertJsonPath('draft', fn ($draft) => str_contains($draft, 'The old summary.'));
    }

    public function test_a_draft_that_does_not_parse_is_reported_not_applied(): void
    {
        $this->signIn();
        $this->makeType();

        $session = $this->sessionWithDraft("- not\n- a draft");

        $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))
            ->assertJsonPath('draft_problem', fn ($problem) => str_contains((string) $problem, 'should be a list of fields'));

        $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id))->assertStatus(422);
        $this->postJson(cp_route('ghostwriter.sessions.entry', $session->id))->assertStatus(422);
    }

    public function test_an_existing_entry_can_be_edited_in_conversation(): void
    {
        $this->signIn();

        Entry::make()->collection('articles')->slug('existing')->published(true)->data([
            'title' => 'Existing Piece',
            'summary' => 'The old summary.',
            'featured_image' => 'articles/existing.jpg',
            'page_builder' => [
                ['id' => 'h1', 'type' => 'hero', 'enabled' => true, 'heading' => 'Old heading', 'image' => 'heroes/existing.mp4'],
                ['id' => 'c1', 'type' => 'cards', 'enabled' => true, 'heading' => 'Uses', 'items' => [['id' => 'r1', 'text' => 'First'], ['id' => 'r2', 'text' => 'Second']]],
                ['id' => 'g1', 'type' => 'gallery', 'enabled' => false, 'caption' => 'Switched off'],
                ['id' => 'l1', 'type' => 'long_form', 'enabled' => true, 'width' => 'wide', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Old words.']]]]],
            ],
        ])->save();

        $entry = Entry::query()->where('slug', 'existing')->first();

        $detail = $this->postJson(cp_route('ghostwriter.entries.session', $entry->id()))->assertOk()
            ->assertJsonPath('editing', true)
            ->assertJsonPath('title', 'Existing Piece')
            ->json();

        // The saved entry is the draft, in the form drafts are written in.
        $this->assertStringContainsString('heading: \'Old heading\'', $detail['draft']);
        $this->assertStringContainsString('Old words.', $detail['draft']);
        $this->assertStringNotContainsString('Switched off', $detail['draft']);
        $this->assertStringNotContainsString('existing.jpg', $detail['draft']);

        // Opening it again carries on the same conversation.
        $this->postJson(cp_route('ghostwriter.entries.session', $entry->id()))->assertJsonPath('id', $detail['id']);

        // It is not offered on the create screen as something to carry on with.
        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertJsonPath('sessions', []);

        $this->ai->respond('writer', "<reply>Done.</reply>\n<draft>\ntitle: Existing Piece\nsummary: The new summary.\npage_builder:\n  - type: long_form\n    content: New words.\n  - type: hero\n    heading: New heading\n  - type: cards\n    heading: Uses\n    items:\n      - text: First, reworded\n      - text: Second\n      - text: Third\n</draft>");

        $this->postJson(cp_route('ghostwriter.sessions.message', $detail['id']), ['message' => 'New heading and summary, move the prose first.'])->assertOk();
        $this->runTurn(app(SessionRepository::class)->find($detail['id']));

        // The writer was shown the entry as the current draft.
        $this->ai->assertSent('writer', fn (TextRequest $prompt) => str_contains($prompt->prompt, '<current_draft>') && str_contains($prompt->prompt, 'Old heading'));

        $values = $this->postJson(cp_route('ghostwriter.sessions.apply', $detail['id']))->assertOk()->json('values');
        $blocks = collect($values['page_builder'])->keyBy('type');

        $this->assertSame('The new summary.', $values['summary']);
        $this->assertSame(['long_form', 'hero', 'cards', 'gallery'], array_column($values['page_builder'], 'type'));

        // The words changed; what the writer never sees stayed with its block.
        $this->assertSame('New heading', $blocks['hero']['heading']);
        $this->assertNotEmpty($blocks['hero']['image']);
        $this->assertSame('h1', $blocks['hero']['_id'] ?? $blocks['hero']['id']);
        $this->assertSame('wide', $blocks['long_form']['width']);
        $this->assertSame(['First, reworded', 'Second', 'Third'], array_column($blocks['cards']['items'], 'text'));
        $this->assertFalse($blocks['gallery']['enabled']);

        // The image field the draft does not cover is not sent, so the form keeps it.
        $this->assertArrayNotHasKey('featured_image', $values);
    }

    public function test_editing_starts_from_the_form_as_it_stands_and_keeps_its_unsaved_changes(): void
    {
        $this->signIn();

        Entry::make()->collection('articles')->slug('existing')->published(true)->data([
            'title' => 'Existing Piece',
            'summary' => 'The saved summary.',
            'page_builder' => [
                ['id' => 'h1', 'type' => 'hero', 'enabled' => true, 'heading' => 'Old heading', 'image' => 'heroes/saved.mp4'],
            ],
        ])->save();

        $entry = Entry::query()->where('slug', 'existing')->first();

        // The form as the person has it: a summary typed and a hero image
        // swapped, neither saved yet.
        $form = $entry->blueprint()->fields()->addValues($entry->data()->all())->preProcess()->values()->all();
        $form['summary'] = 'Typed but not saved.';
        $form['page_builder'][0]['image'] = 'heroes/unsaved.mp4';

        $detail = $this->postJson(cp_route('ghostwriter.entries.session', $entry->id()), ['values' => $form])->assertOk()->json();

        $this->assertStringContainsString('Typed but not saved.', $detail['draft']);
        $this->assertStringNotContainsString('The saved summary.', $detail['draft']);

        $this->ai->respond('writer', "<reply>Done.</reply>\n<draft>\ntitle: Existing Piece\nsummary: Typed but not saved.\npage_builder:\n  - type: hero\n    heading: New heading\n</draft>");
        $this->postJson(cp_route('ghostwriter.sessions.message', $detail['id']), ['message' => 'A new heading.'])->assertOk();
        $this->runTurn(app(SessionRepository::class)->find($detail['id']));

        $values = $this->postJson(cp_route('ghostwriter.sessions.apply', $detail['id']), ['values' => $form])->assertOk()->json('values');

        // The words changed; the image swapped by hand in the form stayed swapped.
        $this->assertSame('New heading', $values['page_builder'][0]['heading']);
        $this->assertSame('heroes/unsaved.mp4', $values['page_builder'][0]['image']);
        $this->assertSame('Typed but not saved.', $values['summary']);

        // Without the form's values (an older script), the entry as saved is the base, as before.
        $saved = $this->postJson(cp_route('ghostwriter.sessions.apply', $detail['id']))->assertOk()->json('values');
        $this->assertSame('heroes/saved.mp4', $saved['page_builder'][0]['image']);
    }

    private function startedSession(): Session
    {
        $type = app(TypeRepository::class)->find('articles');

        $session = Session::start('articles', ['what' => 'A faceted search.']);
        $session->addMessage('user', app(Studio::class)->brief($type, $session));
        $session->status = Session::WORKING;

        return app(SessionRepository::class)->save($session);
    }

    private function sessionWithDraft(string $draft): Session
    {
        $session = $this->startedSession();
        $session->draft = $draft;
        $session->status = Session::IDLE;

        return app(SessionRepository::class)->save($session);
    }

    private function runTurn(Session $session): void
    {
        (new RunSessionTurn($session->id))->handle(app(SessionRepository::class), app(TypeRepository::class), app(Studio::class), app(VoiceGuide::class));
    }
}
