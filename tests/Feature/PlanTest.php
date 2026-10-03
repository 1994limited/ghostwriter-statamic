<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Jobs\FillBrief;
use NineteenNinetyFour\Ghostwriter\Jobs\RunSessionTurn;
use NineteenNinetyFour\Ghostwriter\Jobs\SuggestIdeas;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Entry;

/**
 * The content plan: ideas for what is missing, and drafting from one.
 */
class PlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeArticlesCollection();
        $this->makeArticle('cost', 'What Does a Website Cost?', 'A paragraph about cost that is long enough to count as a sample.');
        $this->makeType();
    }

    public function test_ghostwriter_suggests_what_the_site_is_missing(): void
    {
        $plan = app(Plan::class);
        $plan->add(['title' => 'Rebuild or repair?', 'collection' => 'articles']);
        $plan->dismiss($plan->add(['title' => 'Our office dog', 'collection' => 'articles'])->id);

        $this->ai->respond('planner', "<ideas>\n- title: How to brief a web agency\n  collection: articles\n  type: articles\n  why: The cost guide sends readers off to get quotes with nothing on how to ask for one.\n  notes: For an owner about to approach agencies. [Add a brief we thought was good]\n- title: Rebuild or repair?\n  collection: articles\n- title: A page about nothing\n  collection: nowhere\n- title: Slow site, lost sale\n  collection: articles\n  type: made-up\n</ideas>");

        $this->runJob(new SuggestIdeas(['articles'], 'More for owners.'));

        // Two suggestions wait to be looked over; the repeat and the one for
        // a collection not planned for are left out. Nothing is on the plan yet.
        $pending = app(PlanStore::class)->state()->pending;

        $this->assertSame(['How to brief a web agency', 'Slow site, lost sale'], array_column($pending, 'title'));
        $this->assertSame('articles', $pending[0]['type']);
        $this->assertNull($pending[1]['type']);
        $this->assertCount(2, app(PlanStore::class)->ideas());

        // The person keeps the first and not the second.
        $this->signIn();
        $this->postJson(cp_route('ghostwriter.plan.accept'), ['chosen' => [0]])->assertOk()->assertJsonPath('pending', []);

        $this->assertSame([
            'Rebuild or repair?' => 'open',
            'Our office dog' => 'dismissed',
            'How to brief a web agency' => 'open',
            'Slow site, lost sale' => 'dismissed',
        ], $this->statuses());
        $this->assertSame('suggested', collect(app(PlanStore::class)->ideas())->firstWhere('title', 'How to brief a web agency')->source);

        // It was shown what exists, what is planned and what was turned down.
        $this->ai->assertSent('planner', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'More for owners.'));

        $this->assertSame('idle', app(PlanStore::class)->state()->status);
    }

    public function test_suggestions_wait_until_they_are_looked_over_or_dropped(): void
    {
        $this->signIn();

        app(Plan::class)->changeState(fn (PlanState $state) => $state->pending = [
            ['title' => 'How to brief a web agency', 'collection' => 'articles', 'type' => null, 'why' => 'Asked often.', 'notes' => ''],
            ['title' => 'Slow site, lost sale', 'collection' => 'articles', 'type' => null, 'why' => '', 'notes' => ''],
        ]);

        // Coming back to the plan, they are still there to look over.
        $this->get(cp_route('ghostwriter.plan.show'))->assertOk()->assertInertia(fn ($page) => $page->has('plan.pending', 2));
        $this->getJson(cp_route('ghostwriter.plan.status'))->assertJsonCount(2, 'pending');

        // Only dropping them throws them away, and nothing is remembered.
        $this->postJson(cp_route('ghostwriter.plan.accept'), ['chosen' => [], 'discard' => true])->assertOk()->assertJsonPath('pending', []);
        $this->assertCount(0, app(PlanStore::class)->ideas());
    }

    public function test_ideas_cut_off_twice_are_kept_as_far_as_they_got(): void
    {
        $this->ai->respond('planner', new TextResponse("<ideas>\n- title: How to brief a web agency\n  collection: articles\n- title: Rebuild or re", StopReason::MaxTokens));

        $this->runJob(new SuggestIdeas(['articles']));

        $this->assertCount(2, $this->ai->prompted('planner'));
        $this->assertSame('idle', app(PlanStore::class)->state()->status);
        $this->assertContains('How to brief a web agency', array_column(app(PlanStore::class)->state()->pending, 'title'));
    }

    public function test_the_plan_screen_adds_dismisses_and_starts_a_search(): void
    {
        Bus::fake([SuggestIdeas::class]);
        $this->signIn();

        $this->postJson(cp_route('ghostwriter.plan.store'), ['title' => 'Nowhere', 'collection' => 'nowhere'])->assertStatus(422);

        $plan = $this->postJson(cp_route('ghostwriter.plan.store'), ['title' => 'Rebuild or repair?', 'collection' => 'articles', 'notes' => 'For owners.'])
            ->assertOk()
            ->assertJsonPath('ideas.0.title', 'Rebuild or repair?')
            ->assertJsonPath('ideas.0.collection_title', 'Articles')
            ->json();

        $idea = $plan['ideas'][0];

        $this->assertStringContainsString('ghostwriter=new&idea='.$idea['id'], $idea['draft_url']);

        $this->patchJson($idea['update_url'], ['status' => 'dismissed'])->assertJsonPath('ideas.0.status', 'dismissed');
        $this->patchJson($idea['update_url'], ['status' => 'open'])->assertJsonPath('ideas.0.status', 'open');

        $this->postJson(cp_route('ghostwriter.plan.suggest'), ['collections' => ['articles', 'nowhere'], 'steer' => 'Ecommerce.'])
            ->assertOk()
            ->assertJsonPath('status', 'working');

        Bus::assertDispatchedAfterResponse(SuggestIdeas::class, fn ($job) => $job->collections === ['articles'] && $job->steer === 'Ecommerce.');
    }

    public function test_the_list_can_be_cleared_without_touching_started_pieces(): void
    {
        $this->signIn();

        $plan = app(Plan::class);
        $plan->add(['title' => 'One', 'collection' => 'articles']);
        $plan->add(['title' => 'Two', 'collection' => 'articles']);
        $plan->start($plan->add(['title' => 'Started', 'collection' => 'articles'])->id, $this->sessions()->save($this->makeSession('any:articles'))->id);
        $plan->dismiss($plan->add(['title' => 'No', 'collection' => 'articles'])->id);

        $this->deleteJson(cp_route('ghostwriter.plan.clear'), ['status' => 'drafted'])->assertStatus(422);
        $this->deleteJson(cp_route('ghostwriter.plan.clear'), ['status' => 'open'])->assertOk();

        $this->assertSame(['Started' => 'drafted', 'No' => 'dismissed'], $this->statuses());
    }

    public function test_an_idea_is_offered_on_the_create_screen_and_marked_drafted_when_started(): void
    {
        Bus::fake([RunSessionTurn::class, FillBrief::class]);
        $this->signIn();

        $idea = app(Plan::class)->add(['title' => 'Rebuild or repair?', 'collection' => 'articles', 'why' => 'Nothing on it yet.', 'notes' => 'Start with the roof.']);

        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))
            ->assertJsonPath('ideas.0.id', $idea->id)
            ->assertJsonPath('ideas.0.why', 'Nothing on it yet.');

        // "Draft this": no question first; the brief is filled in from the idea.
        $session = $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'), ['idea' => $idea->id])
            ->assertOk()
            ->assertJsonPath('stage', 'filling')
            ->assertJsonPath('status', Session::WORKING)
            ->assertJsonPath('messages', [])
            ->json('id');

        Bus::assertDispatchedAfterResponse(FillBrief::class, fn (FillBrief $job) => $job->sessionId === $session);
        Bus::assertNotDispatchedAfterResponse(RunSessionTurn::class);

        $this->ai->respond('brief-filler', "<title>Rebuild or repair?</title>\n<brief>\nwhat: Whether to rebuild the old mill or repair it.\n</brief>");
        $this->runJob(new FillBrief($session));

        $this->ai->assertSent('brief-filler', fn (TextRequest $request) => str_contains($request->prompt, 'Working title: Rebuild or repair?') && str_contains($request->prompt, "Nothing on it yet.\n\nStart with the roof."));
        $this->getJson(cp_route('ghostwriter.sessions.show', $session))
            ->assertJsonPath('stage', 'proposed')
            ->assertJsonPath('brief.title', 'Rebuild or repair?')
            ->assertJsonPath('brief.answers.what', 'Whether to rebuild the old mill or repair it.');

        $idea = app(PlanStore::class)->find($idea->id);

        $this->assertSame(Idea::DRAFTED, $idea->status);
        $this->assertSame($session, $idea->session);

        // No longer offered as something to write.
        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertJsonPath('ideas', []);

        // The plan shows where it has got to and how to pick it back up.
        $planned = $this->getJson(cp_route('ghostwriter.plan.status'))->json('ideas.0');

        // The brief card waits to be checked.
        $this->assertSame('interview', $planned['stage']);
        $this->assertFalse($planned['finished']);
        $this->assertStringContainsString('ghostwriter='.$session, $planned['resume_url']);

        // Remove the conversation and it is an idea again.
        $this->deleteJson(cp_route('ghostwriter.sessions.destroy', $session))->assertOk();
        $this->getJson(cp_route('ghostwriter.plan.status'))->assertJsonPath('ideas.0.status', 'open');
    }

    public function test_a_started_piece_goes_back_to_ideas_until_it_is_finished(): void
    {
        Bus::fake([RunSessionTurn::class, FillBrief::class]);
        $this->signIn();

        $plan = app(Plan::class);
        $idea = $plan->add(['title' => 'Rebuild or repair?', 'collection' => 'articles']);
        $session = $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'), ['idea' => $idea->id])->assertOk()->json('id');
        $update = cp_route('ghostwriter.plan.update', $idea->id);

        // Started and given up on: back on the plan, the conversation kept.
        $this->patchJson($update, ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('ideas.0.status', 'open')
            ->assertJsonPath('ideas.0.session', null);
        $this->assertNotNull($this->sessions()->find($session));

        // Started again and its entry saved: finished, so it stays where it is.
        $plan->start($idea->id, $session);
        $stored = $this->sessions()->find($session);
        $stored->status = Session::IDLE;
        $stored->recordId = Entry::query()->where('collection', 'articles')->first()->id();
        $this->sessions()->save($stored);

        $this->patchJson($update, ['status' => 'open'])->assertStatus(409);
        $this->assertSame(Idea::DRAFTED, app(PlanStore::class)->find($idea->id)->status);
    }

    /**
     * @return array<string, string>
     */
    private function statuses(): array
    {
        return collect(app(PlanStore::class)->ideas())->mapWithKeys(fn (Idea $idea) => [$idea->title => $idea->status])->all();
    }
}
