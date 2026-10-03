<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use NineteenNinetyFour\Ghostwriter\Actions\WriteWithGhostwriter;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\Analysis;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\Waiting;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefThread;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio as CoreStudio;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\AnalyseCollection;
use NineteenNinetyFour\Ghostwriter\Jobs\FillBrief;
use NineteenNinetyFour\Ghostwriter\Jobs\RunSessionTurn;
use NineteenNinetyFour\Ghostwriter\Jobs\SuggestKinds;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\Widgets\Ghostwriter;
use NineteenNinetyFour\Ghostwriter\WorkStates;
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

        $this->runJob(new AnalyseCollection('articles'));

        $type = app(TypeRepository::class)->find('articles');

        $this->assertSame('Project article', $type->title);
        $this->assertSame('articles', $type->group);
        $this->assertSame(['what', 'avoid'], array_column($type->questions, 'handle'));
        $this->assertSame('idle', app(KindStore::class)->analysis('articles')->status);

        // The analyst was shown the fields, the pattern and a real entry.
        $this->ai->assertSent('type-analyst', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'in this order: hero, long_form, cards, related')
            && str_contains($prompt->prompt, 'A paragraph about'));
    }

    public function test_an_unreadable_analysis_is_asked_for_again_then_fails_without_saving_anything(): void
    {
        $this->ai->respond('type-analyst', 'Sorry, I cannot help with that.', 'Still no.');

        $this->runJob(new AnalyseCollection('articles'));

        $this->assertNull(app(TypeRepository::class)->find('articles'));
        $this->assertSame('failed', app(KindStore::class)->analysis('articles')->status);

        // The second ask said what was wrong with the first answer.
        $this->ai->assertSent('type-analyst', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'could not be read: there was no <type> block'));
    }

    public function test_an_unreadable_reply_is_logged_whole_only_when_the_debug_setting_is_on(): void
    {
        config([
            'logging.channels.ghostwriter-test' => ['driver' => 'single', 'path' => $this->workspace.'/ghostwriter.log'],
            'ghostwriter.log_channel' => 'ghostwriter-test',
        ]);

        $analyse = function (): string {
            $this->app->forgetInstance(CoreStudio::class);
            Log::forgetChannel('ghostwriter-test');
            File::delete($this->workspace.'/ghostwriter.log');
            $this->ai->respond('type-analyst', 'Sorry, I cannot help with SECRET-REPLY-TEXT.', 'Still no.');

            $this->runJob(new AnalyseCollection('articles'));

            return (string) File::get($this->workspace.'/ghostwriter.log');
        };

        // Off by default: the log says what was wrong, not what the model wrote.
        $log = $analyse();
        $this->assertStringContainsString('the type analysis for articles could not be read (there was no <type> block)', $log);
        $this->assertStringNotContainsString('SECRET-REPLY-TEXT', $log);

        config(['ghostwriter.debug.log_replies' => true]);
        $this->assertStringContainsString('SECRET-REPLY-TEXT', $analyse());
    }

    public function test_a_type_in_a_code_fence_or_fixed_on_the_second_try_is_read(): void
    {
        $this->ai->respond('type-analyst',
            "<type>\n```yaml\ntitle: Fenced\nquestions: not a list\n```\n</type>",
            "<type>\n```yaml\ntitle: Fenced\nquestions:\n  - handle: what\n    label: What?\n```\n</type>",
        );

        $this->runJob(new AnalyseCollection('articles'));

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

        $this->runJob(new SuggestKinds(['articles', 'nowhere']));

        $state = app(KindStore::class)->suggestions('articles');

        $this->assertSame('idle', $state->status);
        $this->assertSame(3, $state->records);
        $this->assertSame(['Project write-up'], array_column($state->suggestions, 'title'));
        $this->assertSame([$ids['one'], $ids['two']], $state->suggestions[0]['examples']);
        $this->assertSame('article', $state->suggestions[0]['blueprint']);

        // The scout was shown each entry: its title, how it is built and how it opens.
        $this->ai->assertSent('kind-finder', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'built as: hero, long_form, cards, related') && str_contains($prompt->prompt, 'opens: "A summary line about One'));

        $kinds = $this->getJson(cp_route('ghostwriter.kinds.show', 'articles'))->assertOk()->json('kinds');
        $suggestion = $kinds['suggestions'][0];

        $this->assertSame(['One', 'Two'], $suggestion['titles']);

        // Learned: the same job as teaching by hand, with the kind's name and entries.
        $this->postJson($suggestion['learn_url'])->assertOk()->assertJsonPath('state.status', 'working')->assertJsonPath('kinds.suggestions', []);

        Bus::assertDispatchedAfterResponse(AnalyseCollection::class, fn ($job) => $job->title === 'Project write-up' && $job->examples === [$ids['one'], $ids['two']]);

        $this->postJson($suggestion['learn_url'])->assertNotFound();
    }

    public function test_the_kinds_poll_says_when_learning_a_kind_has_finished_or_failed(): void
    {
        $this->signIn();
        $this->makeArticlesCollection();

        app(KindStore::class)->saveAnalysis('articles', new Analysis('working'));
        $this->getJson(cp_route('ghostwriter.kinds.show', 'articles'))->assertOk()->assertJsonPath('state.status', 'working');

        app(KindStore::class)->saveAnalysis('articles', new Analysis('failed', 'The model gave up.'));
        $this->getJson(cp_route('ghostwriter.kinds.show', 'articles'))
            ->assertJsonPath('state.status', 'failed')
            ->assertJsonPath('state.error', 'The model gave up.');

        app(KindStore::class)->saveAnalysis('articles', new Analysis('idle'));
        $this->getJson(cp_route('ghostwriter.kinds.show', 'articles'))->assertJsonPath('state.status', 'idle');
    }

    public function test_a_scout_with_nothing_to_add_is_not_a_failure(): void
    {
        $this->makeType();
        $this->ai->respond('kind-finder', 'Both entries are already covered by the Article kind, so there is nothing to suggest.');

        $this->runJob(new SuggestKinds(['articles']));

        $state = app(KindStore::class)->suggestions('articles');

        $this->assertSame('idle', $state->status);
        $this->assertSame([], $state->suggestions);
        $this->assertNotNull($state->checkedAt);
    }

    public function test_a_turned_down_kind_is_not_suggested_again_and_learn_all_queues_the_rest(): void
    {
        Bus::fake([AnalyseCollection::class]);
        $this->signIn();

        app(WorkStates::class)->changeSuggestions('articles', fn (KindSuggestions $state) => $state->store([
            ['title' => 'Press release', 'description' => '', 'why' => '', 'examples' => ['a', 'b'], 'blueprint' => null],
            ['title' => 'Event', 'description' => '', 'why' => '', 'examples' => ['c', 'd'], 'blueprint' => null],
            ['title' => 'Award', 'description' => '', 'why' => '', 'examples' => ['e', 'f'], 'blueprint' => null],
        ], 3));

        $first = app(KindStore::class)->suggestions('articles')->suggestions[0];

        $this->postJson(cp_route('ghostwriter.kinds.dismiss', ['articles', $first['id']]))->assertOk()->assertJsonCount(2, 'kinds.suggestions');
        $this->assertSame(['Press release'], app(KindStore::class)->suggestions('articles')->dismissed);

        // Next time the scout looks, it is told what was turned down.
        $this->ai->respond('kind-finder', "<kinds>\n- title: Press release\n  examples: [\"x\", \"y\"]\n</kinds>");
        app(Studio::class)->suggestKinds(Collection::findByHandle('articles'), app(TypeRepository::class), app(KindStore::class)->suggestions('articles')->dismissed);
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
        app(WorkStates::class)->changeSuggestions('posts', fn (KindSuggestions $state) => $state->status = 'working');

        $this->postJson(cp_route('ghostwriter.kinds.suggest_all'))
            ->assertOk()
            ->assertJsonPath('collections.articles.kinds.status', 'working')
            ->assertJsonPath('collections.posts.kinds.status', 'working');

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

        $this->runJob(new AnalyseCollection('articles', kinds: [['title' => 'Event', 'examples' => []], ['title' => 'Award', 'examples' => []]]));

        $this->assertSame('Event', app(TypeRepository::class)->find('event')->title);
        $this->assertNull(app(TypeRepository::class)->find('award'));
        $this->assertSame('failed', app(KindStore::class)->analysis('articles')->status);
        $this->assertStringStartsWith('Award: ', app(KindStore::class)->analysis('articles')->error);

        // A second type with the same title gets its own handle.
        $this->assertSame('event-2', app(TypeRepository::class)->handleFor('Event', 'articles'));
    }

    public function test_get_started_checks_a_collection_for_kinds_the_first_time_and_after_ten_more_entries(): void
    {
        Bus::fake([SuggestKinds::class]);
        $this->signIn();

        $this->get(cp_route('ghostwriter.setup.show'))->assertOk();
        Bus::assertDispatchedAfterResponse(SuggestKinds::class, fn ($job) => $job->collections === ['articles']);
        $this->assertSame('working', app(KindStore::class)->suggestions('articles')->status);

        // Checked, with three entries: not again until ten more are published.
        app(WorkStates::class)->changeSuggestions('articles', fn (KindSuggestions $state) => $state->store([], 3));
        Bus::fake([SuggestKinds::class]);
        $this->get(cp_route('ghostwriter.setup.show'))->assertOk();
        Bus::assertNotDispatchedAfterResponse(SuggestKinds::class);

        foreach (range(4, 13) as $n) {
            $this->makeArticle("more-{$n}", "More {$n}", 'Another paragraph long enough to be read as a sample of writing.');
        }

        $this->get(cp_route('ghostwriter.setup.show'))->assertOk();
        Bus::assertDispatchedAfterResponse(SuggestKinds::class);

        // Switched off, nothing happens by itself.
        config(['ghostwriter.suggest_kinds' => false]);
        app(WorkStates::class)->changeSuggestions('articles', function (KindSuggestions $state) {
            $state->status = 'idle';
            $state->checkedAt = null;
        });
        Bus::fake([SuggestKinds::class]);
        $this->get(cp_route('ghostwriter.setup.show'))->assertOk();
        Bus::assertNotDispatchedAfterResponse(SuggestKinds::class);
    }

    public function test_opening_the_dashboard_never_starts_suggesting_kinds(): void
    {
        Bus::fake();
        $this->signIn();

        // A collection never looked at, and one with plenty new since: the
        // dashboard waits for a click all the same.
        $this->makePostsCollection();
        app(WorkStates::class)->changeSuggestions('posts', fn (KindSuggestions $state) => $state->store([], 0));
        foreach (range(1, 12) as $n) {
            $this->makeArticle("more-{$n}", "More {$n}", 'Another paragraph long enough to be read as a sample of writing.');
        }

        $this->get(cp_route('ghostwriter.index'))->assertOk();

        Bus::assertNotDispatched(SuggestKinds::class);
        Bus::assertNotDispatchedAfterResponse(SuggestKinds::class);
        $this->assertNotSame('working', app(KindStore::class)->suggestions('articles')->status);

        // Asked for, it starts.
        $this->postJson(cp_route('ghostwriter.kinds.suggest', 'articles'))->assertOk();
        Bus::assertDispatchedAfterResponse(SuggestKinds::class);
    }

    public function test_a_shared_piece_is_deleted_only_by_its_starter_or_a_manager(): void
    {
        Bus::fake([SuggestKinds::class, RunSessionTurn::class, FillBrief::class]);
        config(['statamic.editions.pro' => true]);
        $this->setTestRoles([
            'tester' => ['access cp', 'access ghostwriter', ...self::WRITER_PERMISSIONS],
            'manager' => ['access cp', 'access ghostwriter', 'edit '.Settings::ADDON.' settings', ...self::WRITER_PERMISSIONS],
        ]);
        $this->makeType();

        $ada = tap(User::make()->email('ada@example.com')->set('name', 'Ada Lovelace')->assignRole('tester'))->save();
        $bob = tap(User::make()->email('bob@example.com')->set('name', 'Bob Byte')->assignRole('tester'))->save();
        $mia = tap(User::make()->email('mia@example.com')->set('name', 'Mia Manager')->assignRole('manager'))->save();

        $piece = function () use ($ada) {
            $session = $this->sessionWithDraft(self::DRAFT);
            $session->startedBy = (string) $ada->id();

            return $this->sessions()->save($session)->id;
        };

        $first = $piece();
        $deleteUrl = function () use (&$first) {
            return collect($this->get(cp_route('ghostwriter.index'))->viewData('page')['props']['sessions'])->firstWhere('id', $first)['delete_url'] ?? null;
        };

        // Bob may carry it on, but is not offered, nor allowed, to delete it.
        $this->actingAs($bob);
        $this->getJson(cp_route('ghostwriter.sessions.show', $first))->assertOk();
        $this->assertNull($deleteUrl());
        $this->deleteJson(cp_route('ghostwriter.sessions.destroy', $first))->assertForbidden();
        $this->assertNotNull($this->sessions()->find($first));

        // Ada started it: she may.
        $this->actingAs($ada);
        $this->assertNotNull($deleteUrl());
        $this->deleteJson(cp_route('ghostwriter.sessions.destroy', $first))->assertOk();
        $this->assertNull($this->sessions()->find($first));

        // So may someone who manages Ghostwriter.
        $first = $piece();
        $this->actingAs($mia);
        $this->assertNotNull($deleteUrl());
        $this->deleteJson(cp_route('ghostwriter.sessions.destroy', $first))->assertOk();
        $this->assertNull($this->sessions()->find($first));
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
            ->assertJsonPath('state.status', 'working');

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

        Bus::fake([RunSessionTurn::class, FillBrief::class]);

        $id = $this->postJson(cp_route('ghostwriter.sessions.store', 'any:articles'), ['examples' => [$one, $elsewhere], 'details' => 'Our new office. We moved in May.'])
            ->assertOk()
            ->json('id');

        // Entries from another collection cannot be the model.
        $session = $this->sessions()->find($id);
        $this->assertSame([$one], $session->examples);

        $this->ai->respond('brief-filler', "<title>Our new office</title>\n<brief>\nsubject: Our new office.\nreader: Clients.\n</brief>");
        $this->runJob(new FillBrief($id));

        // Required answers are checked before the brief is agreed.
        $this->postJson(cp_route('ghostwriter.sessions.brief.agree', $id), ['answers' => ['points' => '']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers.points']);

        $this->postJson(cp_route('ghostwriter.sessions.brief.agree', $id), ['answers' => ['points' => 'We moved in May.']])->assertOk();
        $this->assertStringContainsString('We moved in May.', end($this->sessions()->find($id)->messages)['content']);

        // The general brief has nothing to edit.
        $this->get(cp_route('ghostwriter.types.edit', 'any:articles'))->assertNotFound();
        $this->postJson(cp_route('ghostwriter.sessions.store', 'any:nowhere'), ['details' => 'x'])->assertNotFound();
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

        $this->runJob(new AnalyseCollection('articles', 'Landing page', [$landing]));

        $type = app(TypeRepository::class)->find('landing-page');

        $this->assertSame('Landing page', $type->title);
        $this->assertSame([$landing], $type->examples);
        $this->assertSame('article', $type->variant);

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
        $this->assertSame('articles', $type->group);
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

    public function test_choosing_a_kind_opens_the_conversation_on_the_quick_details(): void
    {
        Bus::fake([RunSessionTurn::class, FillBrief::class]);
        $user = $this->signIn();
        $this->makeType();

        // Opened without details: Ghostwriter asks for them, and nothing runs.
        $id = $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'))
            ->assertOk()
            ->assertJsonPath('stage', 'details')
            ->assertJsonPath('status', Session::IDLE)
            ->assertJsonPath('waiting_on_you', false)
            ->assertJsonPath('messages.0.step', 'ask')
            ->assertJsonPath('messages.0.content', 'What’s it called, and what should it say? A line or two is plenty.')
            ->json('id');

        Bus::assertNotDispatchedAfterResponse(FillBrief::class);

        // The reply: the brief is filled in from it.
        $this->postJson(cp_route('ghostwriter.sessions.message', $id), ['message' => 'Faceted search. For a kitchen appliance maker.'])
            ->assertOk()
            ->assertJsonPath('stage', 'filling')
            ->assertJsonPath('status', Session::WORKING)
            ->assertJsonPath('messages.1.step', 'details');

        Bus::assertDispatchedAfterResponse(FillBrief::class, fn (FillBrief $job) => $job->sessionId === $id);
        Bus::assertNotDispatchedAfterResponse(RunSessionTurn::class);
        $this->assertSame($user->id(), $this->sessions()->find($id)->startedBy);

        // The ask and the reply in one request, as the panel sends them.
        $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'), ['details' => 'Something else.'])
            ->assertOk()
            ->assertJsonPath('stage', 'filling');
    }

    public function test_the_brief_is_filled_in_as_a_card_to_check(): void
    {
        Bus::fake([RunSessionTurn::class, FillBrief::class]);
        $this->signIn();
        $this->makeType();
        $one = Entry::query()->where('slug', 'one')->first()->id();

        $id = $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'), ['examples' => [$one], 'details' => 'Faceted search across 400 products. For a kitchen appliance maker.'])->json('id');

        $this->ai->respond('brief-filler', "<title>Faceted search</title>\n<brief>\nwhat: |\n  A search that narrows 400 products by what the customer needs.\n  [Add: the client and what changed after launch]\navoid: Client names.\nmade_up: ignored\n</brief>");
        $this->runJob(new FillBrief($id));

        // One call, with the reply, the questions and what is already there.
        $this->ai->assertSent('brief-filler', fn (TextRequest $request) => str_contains($request->prompt, 'Faceted search across 400 products.') && str_contains($request->instructions, 'What was built?') && str_contains($request->instructions, '- One'));

        $detail = $this->getJson(cp_route('ghostwriter.sessions.show', $id))
            ->assertJsonPath('stage', 'proposed')
            ->assertJsonPath('status', Session::IDLE)
            ->assertJsonPath('title', 'Faceted search')
            ->assertJsonPath('waiting_on_you', false)
            ->assertJsonPath('brief.title', 'Faceted search')
            ->assertJsonPath('brief.answers', [
                'what' => "A search that narrows 400 products by what the customer needs.\n[Add: the client and what changed after launch]",
                'avoid' => 'Client names.',
            ])
            ->assertJsonPath('brief.examples', [$one])
            ->assertJsonPath('brief.open', ['what'])
            ->assertJsonPath('brief.agreed', false)
            ->assertJsonPath('brief_text', null)
            ->json();

        // The ask, the reply and the card, which says what is in brackets.
        $this->assertSame(['ask', 'details', 'card'], array_column($detail['messages'], 'step'));
        $this->assertSame('Here’s the brief. Change anything that isn’t right, then start writing. Anything in [square brackets] is for you to fill in.', $detail['messages'][2]['content']);

        // Nothing is written until the person agrees; a message waits for that.
        Bus::assertNotDispatchedAfterResponse(RunSessionTurn::class);
        $this->postJson(cp_route('ghostwriter.sessions.message', $id), ['message' => 'Go on.'])->assertStatus(409);
    }

    public function test_try_again_keeps_the_answers_the_person_changed(): void
    {
        Bus::fake([RunSessionTurn::class, FillBrief::class]);
        $this->signIn();
        $this->makeType();

        $id = $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'), ['details' => 'Faceted search.'])->json('id');
        $this->ai->respond('brief-filler',
            "<title>Faceted search</title>\n<brief>\nwhat: A search.\navoid: Client names.\n</brief>",
            "<title>Search that listens</title>\n<brief>\nwhat: A different search.\navoid: Prices.\n</brief>",
        );
        $this->runJob(new FillBrief($id));

        $this->postJson(cp_route('ghostwriter.sessions.brief.try_again', $id), ['title' => 'Faceted search', 'answers' => ['what' => 'A search, as I put it.', 'avoid' => 'Client names.']])
            ->assertOk()
            ->assertJsonPath('stage', 'filling')
            ->assertJsonPath('status', Session::WORKING);

        Bus::assertDispatchedAfterResponse(FillBrief::class, fn (FillBrief $job) => $job->sessionId === $id);

        $this->runJob(new FillBrief($id));

        // The second call was told what the person changed, and it is kept exactly.
        $this->assertCount(2, $this->ai->requests());
        $this->ai->assertSent('brief-filler', fn (TextRequest $request) => str_contains($request->prompt, 'A search, as I put it.'));

        $detail = $this->getJson(cp_route('ghostwriter.sessions.show', $id))
            ->assertJsonPath('stage', 'proposed')
            ->assertJsonPath('brief.attempt', 2)
            ->assertJsonPath('brief.answers.what', 'A search, as I put it.')
            ->json();

        // Only the latest card is shown, and "Try again" is not a message.
        $this->assertSame(['ask', 'details', 'card'], array_column($detail['messages'], 'step'));

        // Nothing to try again once it is agreed.
        $this->postJson(cp_route('ghostwriter.sessions.brief.agree', $id), [])->assertOk();
        $this->postJson(cp_route('ghostwriter.sessions.brief.try_again', $id), [])->assertStatus(409);
    }

    public function test_agreeing_stores_the_brief_and_starts_writing(): void
    {
        Bus::fake([RunSessionTurn::class, FillBrief::class]);
        $user = $this->signIn();
        $this->makeType();
        [$one, $two] = [Entry::query()->where('slug', 'one')->first()->id(), Entry::query()->where('slug', 'two')->first()->id()];

        $id = $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'), ['details' => 'Faceted search.'])->json('id');
        $this->ai->respond('brief-filler', "<title>Faceted search</title>\n<brief>\nwhat: \"A search. [Add: the client]\"\n</brief>");
        $this->runJob(new FillBrief($id));

        // Square brackets are allowed: they become gaps in the draft.
        $this->postJson(cp_route('ghostwriter.sessions.brief.agree', $id), ['title' => 'Search that listens', 'answers' => ['avoid' => 'Prices.'], 'examples' => [$one, $two]])
            ->assertOk()
            ->assertJsonPath('stage', 'writing')
            ->assertJsonPath('status', Session::WORKING)
            ->assertJsonPath('brief.agreed', true)
            ->assertJsonPath('brief.title', 'Search that listens');

        Bus::assertDispatchedAfterResponse(RunSessionTurn::class, fn (RunSessionTurn $job) => $job->sessionId === $id);

        $session = $this->sessions()->find($id);
        $this->assertSame(['what' => 'A search. [Add: the client]', 'avoid' => 'Prices.'], $session->answers);
        $this->assertSame([$one, $two], $session->examples);
        $this->assertSame((string) $user->id(), (string) $session->runBy);

        // The writer starts from the agreed brief, and sees none of the card's steps.
        $this->ai->respond('writer', '<reply>1. Which client?</reply>');
        $this->runTurn($session);

        $this->ai->assertSent('writer', fn (TextRequest $request) => str_contains($request->prompt, 'Search that listens') && str_contains($request->prompt, 'Prices.') && ! str_contains($request->prompt, 'What’s it called'));

        // The writer's questions are waiting; the card is collapsed in the thread.
        $detail = $this->getJson(cp_route('ghostwriter.sessions.show', $id))
            ->assertJsonPath('stage', 'questions')
            ->assertJsonPath('waiting_on_you', true)
            ->json();
        $this->assertSame(['ask', 'details', 'card', null], array_column($detail['messages'], 'step'));
    }

    public function test_the_agreed_brief_can_be_changed_without_running_a_turn(): void
    {
        Bus::fake([RunSessionTurn::class, FillBrief::class]);
        $this->signIn();
        $this->makeType();

        $id = $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'), ['details' => 'Faceted search.'])->json('id');
        $this->ai->respond('brief-filler', "<title>Faceted search</title>\n<brief>\nwhat: A search.\n</brief>");
        $this->runJob(new FillBrief($id));

        // Not agreed yet: there is nothing to change.
        $this->patchJson(cp_route('ghostwriter.sessions.brief.update', $id), ['answers' => ['avoid' => 'Prices.']])->assertStatus(409);

        $this->postJson(cp_route('ghostwriter.sessions.brief.agree', $id))->assertOk();

        // Refused while the turn runs.
        $this->patchJson(cp_route('ghostwriter.sessions.brief.update', $id), ['answers' => ['avoid' => 'Prices.']])->assertStatus(409);

        $session = $this->sessions()->find($id);
        $session->answer('Here is the draft.', self::DRAFT, 1, 1);
        $this->sessions()->save($session);

        $this->patchJson(cp_route('ghostwriter.sessions.brief.update', $id), ['answers' => ['what' => ''], 'title' => 'Search'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers.what']);

        $this->patchJson(cp_route('ghostwriter.sessions.brief.update', $id), ['answers' => ['avoid' => 'Prices.'], 'title' => 'Search'])
            ->assertOk()
            ->assertJsonPath('status', Session::IDLE)
            ->assertJsonPath('brief.answers.avoid', 'Prices.')
            ->assertJsonPath('brief.agreed', true);

        Bus::assertDispatchedAfterResponseTimes(RunSessionTurn::class, 1);

        $session = $this->sessions()->find($id);
        $this->assertSame('Prices.', $session->answers['avoid']);
        $agreed = array_values(array_filter($session->messages, fn (array $message) => BriefThread::step($message) === BriefThread::AGREED));
        $this->assertStringContainsString('Prices.', $agreed[0]['content']);
        $this->assertStringContainsString('**Working title**'."\n".'Search', $agreed[0]['content']);
    }

    public function test_a_brief_that_could_not_be_filled_in_can_be_tried_again_or_told_more(): void
    {
        Bus::fake([RunSessionTurn::class, FillBrief::class]);
        $this->signIn();
        $this->makeType();

        $id = $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'), ['details' => 'Faceted search.'])->json('id');
        $this->ai->respond('brief-filler', 'I would rather chat about it.', 'Still no.', "<brief>\nwhat: A search.\n</brief>");

        $this->runJob(new FillBrief($id));

        $this->getJson(cp_route('ghostwriter.sessions.show', $id))
            ->assertJsonPath('stage', 'filling')
            ->assertJsonPath('status', Session::FAILED)
            ->assertJsonPath('error', 'Ghostwriter could not fill in the brief from that. Try again, or say a little more about it.')
            ->assertJsonPath('can_retry', true);

        // Try again fills the brief in again, not a writer's turn.
        $this->postJson(cp_route('ghostwriter.sessions.retry', $id))->assertOk()->assertJsonPath('status', Session::WORKING);
        Bus::assertDispatchedAfterResponse(FillBrief::class, fn (FillBrief $job) => $job->sessionId === $id);
        Bus::assertNotDispatchedAfterResponse(RunSessionTurn::class);
        $this->runJob(new FillBrief($id));

        // Or say a little more: it is filled in from everything said.
        $this->postJson(cp_route('ghostwriter.sessions.message', $id), ['message' => 'For a kitchen appliance maker.'])->assertOk()->assertJsonPath('status', Session::WORKING);
        $this->runJob(new FillBrief($id));

        $this->ai->assertSent('brief-filler', fn (TextRequest $request) => str_contains($request->prompt, "Faceted search.\n\nFor a kitchen appliance maker."));
        $this->getJson(cp_route('ghostwriter.sessions.show', $id))->assertJsonPath('stage', 'proposed');
    }

    public function test_a_piece_from_the_brief_screen_carries_on_as_it_was(): void
    {
        $this->signIn();
        $this->makeType();
        $session = $this->startedSession();
        $session->addMessage('assistant', 'Here is the draft.');
        $session->status = Session::IDLE;
        $this->sessions()->save($session);

        $detail = $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))
            ->assertJsonPath('stage', 'writing')
            ->assertJsonPath('brief', null)
            ->json();

        // Its brief behind "Show the brief", as before; the thread starts after it.
        $this->assertStringContainsString('A faceted search.', $detail['brief_text']);
        $this->assertSame([1], array_column($detail['messages'], 'index'));
    }

    public function test_the_writer_can_interview_first_and_draft_second(): void
    {
        app(GuideStore::class)->saveGuide(new Guide(Guide::VOICE, "# Tone of voice\n\nTwo punchlines at most."));

        $type = $this->makeType();
        $session = $this->startedSession();

        $this->ai->respond('writer',
            '<reply>1. Which projects can I cite?</reply>',
            "<reply>Here is the draft.</reply>\n<draft>\n".self::DRAFT."\n</draft>",
        );

        $this->runTurn($session);

        $session = $this->sessions()->find($session->id);
        $this->assertNull($session->draft);
        $this->assertSame('1. Which projects can I cite?', end($session->messages)['content']);

        // The panel is told the writer is waiting for an answer.
        $this->assertTrue(app(Presenter::class)->detail($session)['waiting_on_you']);

        $session->addMessage('user', 'The pub and the fitness app.');
        $this->sessions()->save($session);

        $this->runTurn($session);

        $session = $this->sessions()->find($session->id);
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
        $instructions = app(Studio::class)->writerInstructions($type, app(GuideStore::class)->guide(Guide::VOICE)->body);

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
        $this->sessions()->save($session);

        $this->ai->respond('writer', '<reply>Shortened.</reply><draft>'.str_replace('Because they are for **different** websites.', 'Different websites.', self::DRAFT).'</draft>');

        $this->runTurn($session);

        $this->assertStringContainsString('Different websites.', $this->sessions()->find($session->id)->draft);

        $this->ai->assertSent('writer', fn (TextRequest $prompt) => str_contains($prompt->prompt, '<current_draft>') && str_contains($prompt->prompt, 'Shorten the opening.'));
    }

    public function test_a_failed_call_marks_the_session_failed_and_keeps_the_draft(): void
    {
        $this->makeType();
        $session = $this->startedSession();
        $session->draft = self::DRAFT;
        $this->sessions()->save($session);

        $this->ai->respond('writer', fn () => throw new \RuntimeException('The provider is overloaded.'));

        $this->runTurn($session);

        $session = $this->sessions()->find($session->id);
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
            ->assertJsonPath('error', 'The provider is overloaded.')
            ->assertJsonPath('can_retry', true);

        $this->postJson(cp_route('ghostwriter.sessions.retry', $session->id))
            ->assertOk()
            ->assertJsonPath('status', Session::WORKING)
            ->assertJsonPath('error', null);

        Bus::assertDispatchedAfterResponse(RunSessionTurn::class, fn (RunSessionTurn $job) => $job->sessionId === $session->id);

        // The same message, not a second copy of it.
        $this->assertCount(1, $this->sessions()->find($session->id)->messages);
    }

    public function test_a_turn_whose_worker_stopped_shows_as_failed_and_can_be_tried_again(): void
    {
        Bus::fake([RunSessionTurn::class]);
        $this->signIn();
        $this->makeType();

        // Started an hour ago, and its worker killed before it could answer.
        $session = $this->makeSession('articles', ['what' => 'A faceted search.']);
        $session->addMessage('user', 'Write it.');
        $session->claim(null, app(DomainOptions::class), now()->subHour());
        $this->sessions()->save($session);

        $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))
            ->assertJsonPath('status', Session::FAILED)
            ->assertJsonPath('error', DomainOptions::STOPPED)
            ->assertJsonPath('can_retry', true);

        $this->postJson(cp_route('ghostwriter.sessions.retry', $session->id))
            ->assertOk()
            ->assertJsonPath('status', Session::WORKING);

        Bus::assertDispatchedAfterResponse(RunSessionTurn::class, fn (RunSessionTurn $job) => $job->sessionId === $session->id);

        // Running again since a moment ago, it is working, and a message waits for it.
        $this->postJson(cp_route('ghostwriter.sessions.message', $session->id), ['message' => 'And shorter.'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Ghostwriter is still working on the last message.');
    }

    public function test_a_turn_no_worker_has_picked_up_says_so_after_thirty_seconds(): void
    {
        Bus::fake([RunSessionTurn::class]);
        $this->signIn();
        $this->makeType();

        $session = $this->startedSession();
        $session->status = Session::IDLE;
        $this->sessions()->save($session);

        // A real queue, with no worker.
        Queue::fake();
        config(['queue.default' => 'database', 'queue.connections.database.queue' => 'default']);

        $this->postJson(cp_route('ghostwriter.sessions.message', $session->id), ['message' => 'Shorten it.'])
            ->assertOk()
            ->assertJsonPath('queue_waiting', null);

        $this->travel(31)->seconds();

        $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))
            ->assertJsonPath('queue_waiting', 'Still waiting for a queue worker to pick this up. Is “php artisan queue:work” running?');

        // A worker starts it: nothing more to say.
        $this->ai->respond('writer', '<reply>Shorter.</reply>');
        $this->runTurn($this->sessions()->find($session->id));

        $this->assertNull(app(Waiting::class)->waited('session:'.$session->id));

        // On the sync queue the work runs itself; there is never a worker to wait for.
        config(['queue.default' => 'sync']);
        app(Waiting::class)->queued('session:'.$session->id);
        $this->travel(60)->seconds();
        $this->assertNull(app(Waiting::class)->notice('session:'.$session->id, 'php artisan queue:work'));
    }

    public function test_a_draft_cut_off_twice_is_not_kept(): void
    {
        $this->makeType();
        $session = $this->startedSession();
        $session->draft = self::DRAFT;
        $this->sessions()->save($session);

        $this->ai->respond('writer', new TextResponse("<reply>Here.</reply>\n<draft>\ntitle: Half", StopReason::MaxTokens));

        $this->runTurn($session);

        // Asked once at the writer's limit, then once with twice the room.
        $this->assertSame([16000, 32000], array_map(fn (TextRequest $request) => $request->resolvedMaxTokens(), $this->ai->prompted('writer')));

        $session = $this->sessions()->find($session->id);
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
        $this->assertSame(Session::IDLE, $this->sessions()->find($session->id)->status);
        $this->assertStringContainsString('Because they are for **different** websites.', $this->sessions()->find($session->id)->draft);
    }

    public function test_a_message_cannot_be_sent_while_the_last_one_is_being_answered(): void
    {
        Bus::fake([RunSessionTurn::class]);
        $this->signIn();
        $this->makeType();

        $session = $this->startedSession();

        $this->postJson(cp_route('ghostwriter.sessions.message', $session->id), ['message' => 'Hello?'])->assertStatus(409);

        $session->status = Session::IDLE;
        $this->sessions()->save($session);

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
        // A row ID can be all digits, which PHP turns into an integer key,
        // so compare the keys as strings.
        $this->assertEqualsCanonicalizing($ids, array_map('strval', array_keys($response->json('meta.page_builder.existing'))));

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
        $this->assertSame($entry->id(), $this->sessions()->find($session->id)->recordId);

        // A second entry from the same draft does not overwrite the first.
        $this->postJson(cp_route('ghostwriter.sessions.entry', $session->id))->assertOk();
        $this->assertSame(1, Entry::query()->where('collection', 'articles')->where('slug', 'what-does-a-website-cost-2')->count());
    }

    public function test_with_conversations_kept_private_a_session_belongs_to_whoever_started_it(): void
    {
        config(['ghostwriter.shared_conversations' => false]);
        Bus::fake([SuggestKinds::class]);
        $this->signIn();
        $this->makeType();

        $theirs = $this->sessionWithDraft(self::DRAFT);
        $theirs->startedBy = 'someone-else';
        $this->sessions()->save($theirs);

        // Nobody else sees it listed, or can open it, read it, write in it,
        // use its draft or remove it.
        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertJsonPath('sessions', []);
        $this->get(cp_route('ghostwriter.index'))->assertOk()->assertInertia(fn ($page) => $page->where('counts.in_progress', 0));
        $this->assertSame([], (new Ghostwriter)->component()->toArray()['props']['inProgress']);

        $this->getJson(cp_route('ghostwriter.sessions.show', $theirs->id))->assertForbidden();
        $this->get(cp_route('ghostwriter.sessions.open', $theirs->id))->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.message', $theirs->id), ['content' => 'Shorter.'])->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.retry', $theirs->id))->assertForbidden();
        $this->patchJson(cp_route('ghostwriter.sessions.field', $theirs->id), ['path' => 'title', 'value' => 'Mine now'])->assertForbidden();
        $this->patchJson(cp_route('ghostwriter.sessions.draft', $theirs->id), ['draft' => 'title: Mine'])->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.apply', $theirs->id))->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.image', $theirs->id), ['key' => 'x'])->assertForbidden();
        $this->getJson(cp_route('ghostwriter.sessions.photos', $theirs->id, ['key' => 'x']))->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.photo', $theirs->id), ['key' => 'x', 'source' => 'unsplash', 'id' => '1'])->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.image.copy', $theirs->id), ['key' => 'x'])->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.entry', $theirs->id))->assertForbidden();
        $this->deleteJson(cp_route('ghostwriter.sessions.destroy', $theirs->id))->assertForbidden();

        $this->assertNotNull($this->sessions()->find($theirs->id));
        $this->assertSame(0, Entry::query()->where('collection', 'articles')->where('slug', 'what-does-a-website-cost')->count());

        // The owner, and a super user, carry on as before.
        $mine = $this->sessionWithDraft(self::DRAFT);
        $mine->startedBy = (string) User::current()->id();
        $this->sessions()->save($mine);

        $this->getJson(cp_route('ghostwriter.sessions.show', $mine->id))->assertOk();
        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertJsonCount(1, 'sessions');

        // Solo allows one user, so the same person is made super.
        User::current()->makeSuper()->save();
        $this->getJson(cp_route('ghostwriter.sessions.show', $theirs->id))->assertOk();
        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertJsonCount(2, 'sessions');
    }

    public function test_conversations_are_shared_with_everyone_who_may_use_ghostwriter(): void
    {
        Bus::fake([SuggestKinds::class, RunSessionTurn::class, FillBrief::class]);
        config(['statamic.editions.pro' => true]);
        $this->setTestRoles(['tester' => ['access cp', 'access ghostwriter', ...self::WRITER_PERMISSIONS]]);
        $this->makeType();

        $ada = tap(User::make()->email('ada@example.com')->set('name', 'Ada Lovelace')->assignRole('tester'))->save();
        $bob = tap(User::make()->email('bob@example.com')->set('name', 'Bob Byte')->assignRole('tester'))->save();

        // Ada starts a piece and agrees its brief.
        $this->actingAs($ada);
        $session = $this->agreedSession();
        $stored = $this->sessions()->find($session['id']);
        $stored->addMessage('assistant', 'Here is a draft.');
        $stored->status = Session::IDLE;
        $stored->draft = self::DRAFT;
        $this->sessions()->save($stored);

        // Bob sees it everywhere Ada would, says who started it, and can carry on.
        $this->actingAs($bob);
        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.started_by', 'Ada Lovelace');
        $this->get(cp_route('ghostwriter.index'))->assertOk()->assertInertia(fn ($page) => $page->where('counts.in_progress', 1));

        // The brief card is in the thread for him too, agreed.
        $this->getJson(cp_route('ghostwriter.sessions.show', $session['id']))
            ->assertOk()
            ->assertJsonPath('messages.1.from', 'Ada Lovelace')
            ->assertJsonPath('messages.1.mine', false)
            ->assertJsonPath('messages.2.step', 'card')
            ->assertJsonPath('brief.agreed', true)
            ->assertJsonPath('brief.answers.what', 'A faceted search.')
            ->assertJsonPath('started_by', 'Ada Lovelace')
            ->assertJsonPath('touched_by', null);

        $this->postJson(cp_route('ghostwriter.sessions.message', $session['id']), ['message' => 'Shorter, please.'])
            ->assertOk()
            ->assertJsonPath('messages.4.from', 'Bob Byte')
            ->assertJsonPath('messages.4.mine', true)
            ->assertJsonPath('touched_by', 'you')
            ->assertJsonPath('waiting_on', null);

        // While Bob's request runs, Ada is told whose it is, and waits.
        $this->actingAs($ada);
        $this->getJson(cp_route('ghostwriter.sessions.show', $session['id']))
            ->assertJsonPath('waiting_on', 'Bob Byte')
            ->assertJsonPath('started_by', 'you')
            ->assertJsonPath('touched_by', 'Bob Byte');

        $this->postJson(cp_route('ghostwriter.sessions.message', $session['id']), ['message' => 'Longer!'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Bob Byte is waiting on Ghostwriter. Try again when it has answered.');
        $this->patchJson(cp_route('ghostwriter.sessions.field', $session['id']), ['path' => ['title'], 'value' => 'Mine'])->assertStatus(409);
        $this->patchJson(cp_route('ghostwriter.sessions.draft', $session['id']), ['draft' => 'title: Mine'])->assertStatus(409);

        // Someone without Ghostwriter sees none of it.
        $this->setTestRoles(['tester' => ['access cp', 'access ghostwriter', ...self::WRITER_PERMISSIONS], 'plain' => ['access cp']]);
        $carol = tap(User::make()->email('carol@example.com')->assignRole('plain'))->save();
        $this->actingAs($carol);
        $this->getJson(cp_route('ghostwriter.sessions.show', $session['id']))->assertForbidden();
    }

    public function test_trying_again_on_a_shared_piece_says_who_is_waiting(): void
    {
        Bus::fake([SuggestKinds::class, RunSessionTurn::class, FillBrief::class]);
        config(['statamic.editions.pro' => true]);
        $this->setTestRoles(['tester' => ['access cp', 'access ghostwriter', ...self::WRITER_PERMISSIONS]]);
        $this->makeType();

        $ada = tap(User::make()->email('ada@example.com')->set('name', 'Ada Lovelace')->assignRole('tester'))->save();
        $bob = tap(User::make()->email('bob@example.com')->set('name', 'Bob Byte')->assignRole('tester'))->save();

        $this->actingAs($ada);
        $id = $this->agreedSession()['id'];
        $stored = $this->sessions()->find($id);
        $stored->status = Session::FAILED;
        $stored->error = 'The provider is overloaded.';
        $this->sessions()->save($stored);

        // Bob asks again: he is the one waiting now, and a second go is refused.
        $this->actingAs($bob);
        $this->postJson(cp_route('ghostwriter.sessions.retry', $id))->assertOk()->assertJsonPath('waiting_on', null);
        $this->postJson(cp_route('ghostwriter.sessions.retry', $id))->assertStatus(409);
        $this->assertSame((string) $bob->id(), $this->sessions()->find($id)->runBy);

        $this->actingAs($ada);
        $this->getJson(cp_route('ghostwriter.sessions.show', $id))->assertJsonPath('waiting_on', 'Bob Byte');
        $this->postJson(cp_route('ghostwriter.sessions.message', $id), ['message' => 'Longer!'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Bob Byte is waiting on Ghostwriter. Try again when it has answered.');
    }

    public function test_a_draft_is_not_saved_where_the_person_could_not_create_an_entry(): void
    {
        $this->signInWith(['access ghostwriter', 'view articles entries', 'edit articles entries', 'edit other authors articles entries']);
        $this->makeType();

        $session = $this->sessionWithDraft(self::DRAFT);

        $this->postJson(cp_route('ghostwriter.sessions.entry', $session->id))->assertForbidden();
        $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id))->assertForbidden();

        $this->assertSame(0, Entry::query()->where('collection', 'articles')->where('slug', 'what-does-a-website-cost')->count());
        $this->assertNull($this->sessions()->find($session->id)->recordId);

        // Editing an entry they may edit is still allowed.
        $this->makeArticle('older', 'Older', 'An older piece.');
        $session->source = Entry::query()->where('slug', 'older')->first()->id();
        $this->sessions()->save($session);

        $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id))->assertOk();
    }

    public function test_a_session_is_finished_once_its_entry_exists(): void
    {
        $this->signIn();
        $this->makeType();

        $session = $this->sessionWithDraft(self::DRAFT);
        $stage = fn () => array_intersect_key(app(Presenter::class)->summary($this->sessions()->find($session->id)), ['stage' => 1, 'finished' => 1]);

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
        $session->addMessage('user', 'Make it **shorter**.');
        $session->addMessage('assistant', "Two things:\n\n- **Shorter** opening\n- <script>alert(1)</script> gone");
        $this->sessions()->save($session);

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
        $summary = fn (string $id) => array_intersect_key(app(Presenter::class)->summary($this->sessions()->find($id)), ['stage' => 1, 'finished' => 1]);

        $this->assertSame(['stage' => 'draft', 'finished' => false], $summary($session->id));

        // Changes to an existing entry: put into its form is not saved.
        Entry::make()->collection('articles')->slug('existing')->published(true)->data(['title' => 'Existing Piece', 'summary' => 'Old.', 'updated_at' => now()->subHour()->timestamp])->save();
        $entry = Entry::query()->where('slug', 'existing')->first();
        $editing = $this->postJson(cp_route('ghostwriter.entries.session', $entry->id()))->json('id');

        $edit = $this->sessions()->find($editing);
        $edit->appliedAt = now()->toIso8601String();
        $this->sessions()->save($edit);

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

        $draft = $this->sessions()->find($session->id)->draft;

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
        $session = $this->sessions()->find($id);
        $session->addMessage('user', 'Shorten the summary.');
        $session->addMessage('assistant', 'Done.');
        $session->draft = "title: Existing Piece\nsummary: Shorter.";
        $this->sessions()->save($session);

        // Reopened: the changes are still there.
        $this->postJson(cp_route('ghostwriter.entries.session', $entry->id()))->assertJsonPath('id', $id)->assertJsonPath('draft', "title: Existing Piece\nsummary: Shorter.");

        // Started again: back to the entry as saved, in the same conversation.
        $detail = $this->postJson(cp_route('ghostwriter.entries.session', $entry->id()), ['fresh' => true])->assertJsonPath('id', $id)->json();

        $this->assertStringContainsString('The old summary.', $detail['draft']);
        $this->assertStringContainsString('Start again from the entry', end($detail['messages'])['content'] === 'I have the entry as it stands. Tell me what to change.' ? $detail['messages'][count($detail['messages']) - 2]['content'] : '');

        // Once the changes have been put into the entry, reopening starts afresh too.
        $session = $this->sessions()->find($id);
        $session->draft = "title: Existing Piece\nsummary: Shorter still.";
        $session->appliedAt = now()->toIso8601String();
        $this->sessions()->save($session);

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
        $this->runTurn($this->sessions()->find($detail['id']));

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
        $this->runTurn($this->sessions()->find($detail['id']));

        $values = $this->postJson(cp_route('ghostwriter.sessions.apply', $detail['id']), ['values' => $form])->assertOk()->json('values');

        // The words changed; the image swapped by hand in the form stayed swapped.
        $this->assertSame('New heading', $values['page_builder'][0]['heading']);
        $this->assertSame('heroes/unsaved.mp4', $values['page_builder'][0]['image']);
        $this->assertSame('Typed but not saved.', $values['summary']);

        // Without the form's values (an older script), the entry as saved is the base, as before.
        $saved = $this->postJson(cp_route('ghostwriter.sessions.apply', $detail['id']))->assertOk()->json('values');
        $this->assertSame('heroes/saved.mp4', $saved['page_builder'][0]['image']);
    }

    /**
     * A piece started in the panel, its brief filled in and agreed: the
     * writer's first turn is waiting to run. Fake RunSessionTurn and
     * FillBrief first.
     *
     * @return array<string, mixed>
     */
    private function agreedSession(): array
    {
        $id = $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'), ['details' => 'A faceted search.'])->assertOk()->json('id');

        $this->ai->respond('brief-filler', "<title>Faceted search</title>\n<brief>\nwhat: A faceted search.\n</brief>");
        $this->runJob(new FillBrief($id));

        return $this->postJson(cp_route('ghostwriter.sessions.brief.agree', $id))->assertOk()->json();
    }

    private function startedSession(): Session
    {
        $type = app(TypeRepository::class)->find('articles');

        $session = $this->makeSession('articles', ['what' => 'A faceted search.']);
        $session->addMessage('user', app(Studio::class)->brief($type, $session));
        $session->status = Session::WORKING;

        return $this->sessions()->save($session);
    }

    private function sessionWithDraft(string $draft): Session
    {
        $session = $this->startedSession();
        $session->draft = $draft;
        $session->status = Session::IDLE;

        return $this->sessions()->save($session);
    }

    private function runTurn(Session $session): void
    {
        $this->runJob(new RunSessionTurn($session->id));
    }
}
