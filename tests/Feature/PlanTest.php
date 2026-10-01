<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Jobs\RunSessionTurn;
use NineteenNinetyFour\Ghostwriter\Jobs\SuggestIdeas;
use NineteenNinetyFour\Ghostwriter\Planning\IdeaRepository;
use NineteenNinetyFour\Ghostwriter\Planning\PlanState;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;

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
        $ideas = app(IdeaRepository::class);
        $ideas->add(['title' => 'Rebuild or repair?', 'collection' => 'articles']);
        $ideas->update($ideas->add(['title' => 'Our office dog', 'collection' => 'articles'])['id'], ['status' => IdeaRepository::DISMISSED]);

        $this->ai->respond('planner', "<ideas>\n- title: How to brief a web agency\n  collection: articles\n  type: articles\n  why: The cost guide sends readers off to get quotes with nothing on how to ask for one.\n  notes: For an owner about to approach agencies. [Add a brief we thought was good]\n- title: Rebuild or repair?\n  collection: articles\n- title: A page about nothing\n  collection: nowhere\n- title: Slow site, lost sale\n  collection: articles\n  type: made-up\n</ideas>");

        (new SuggestIdeas(['articles'], 'More for owners.'))->handle(app(Studio::class), app(TypeRepository::class), $ideas, app(VoiceGuide::class), app(PlanState::class));

        // Two suggestions wait to be looked over; the repeat and the one for
        // a collection not planned for are left out. Nothing is on the plan yet.
        $pending = app(PlanState::class)->get()['pending'];

        $this->assertSame(['How to brief a web agency', 'Slow site, lost sale'], array_column($pending, 'title'));
        $this->assertSame('articles', $pending[0]['type']);
        $this->assertNull($pending[1]['type']);
        $this->assertCount(2, $ideas->all());

        // The person keeps the first and not the second.
        $this->signIn();
        $this->postJson(cp_route('ghostwriter.plan.accept'), ['chosen' => [0]])->assertOk()->assertJsonPath('pending', []);

        $this->assertSame([
            'Rebuild or repair?' => 'open',
            'Our office dog' => 'dismissed',
            'How to brief a web agency' => 'open',
            'Slow site, lost sale' => 'dismissed',
        ], $ideas->all()->pluck('status', 'title')->all());
        $this->assertSame('suggested', $ideas->all()->firstWhere('title', 'How to brief a web agency')['source']);

        // It was shown what exists, what is planned and what was turned down.
        $this->ai->assertSent('planner', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'More for owners.'));

        $this->assertSame(PlanState::IDLE, app(PlanState::class)->get()['status']);
    }

    public function test_ideas_cut_off_twice_are_kept_as_far_as_they_got(): void
    {
        $this->ai->respond('planner', new TextResponse("<ideas>\n- title: How to brief a web agency\n  collection: articles\n- title: Rebuild or re", StopReason::MaxTokens));

        (new SuggestIdeas(['articles']))->handle(app(Studio::class), app(TypeRepository::class), app(IdeaRepository::class), app(VoiceGuide::class), app(PlanState::class));

        $this->assertCount(2, $this->ai->prompted('planner'));
        $this->assertSame(PlanState::IDLE, app(PlanState::class)->get()['status']);
        $this->assertContains('How to brief a web agency', array_column(app(PlanState::class)->get()['pending'], 'title'));
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
            ->assertJsonPath('status', PlanState::WORKING);

        Bus::assertDispatchedAfterResponse(SuggestIdeas::class, fn ($job) => $job->collections === ['articles'] && $job->steer === 'Ecommerce.');
    }

    public function test_the_list_can_be_cleared_without_touching_started_pieces(): void
    {
        $this->signIn();

        $ideas = app(IdeaRepository::class);
        $ideas->add(['title' => 'One', 'collection' => 'articles']);
        $ideas->add(['title' => 'Two', 'collection' => 'articles']);
        $ideas->update($ideas->add(['title' => 'Started', 'collection' => 'articles'])['id'], ['status' => IdeaRepository::DRAFTED, 'session' => 'x']);
        $ideas->update($ideas->add(['title' => 'No', 'collection' => 'articles'])['id'], ['status' => IdeaRepository::DISMISSED]);

        $this->deleteJson(cp_route('ghostwriter.plan.clear'), ['status' => 'drafted'])->assertStatus(422);
        $this->deleteJson(cp_route('ghostwriter.plan.clear'), ['status' => 'open'])->assertOk();

        $this->assertSame(['Started' => 'drafted', 'No' => 'dismissed'], $ideas->all()->pluck('status', 'title')->all());
    }

    public function test_an_idea_is_offered_on_the_create_screen_and_marked_drafted_when_started(): void
    {
        Bus::fake([RunSessionTurn::class]);
        $this->signIn();

        $idea = app(IdeaRepository::class)->add(['title' => 'Rebuild or repair?', 'collection' => 'articles', 'why' => 'Nothing on it yet.']);

        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))
            ->assertJsonPath('ideas.0.id', $idea['id'])
            ->assertJsonPath('ideas.0.why', 'Nothing on it yet.');

        $session = $this->postJson(cp_route('ghostwriter.sessions.store', 'articles'), ['answers' => ['what' => 'Whether to rebuild.'], 'idea' => $idea['id']])->assertOk()->json('id');

        $idea = app(IdeaRepository::class)->find($idea['id']);

        $this->assertSame(IdeaRepository::DRAFTED, $idea['status']);
        $this->assertSame($session, $idea['session']);

        // No longer offered as something to write.
        $this->getJson(cp_route('ghostwriter.collections.show', 'articles'))->assertJsonPath('ideas', []);

        // The plan shows where it has got to and how to pick it back up.
        $planned = $this->getJson(cp_route('ghostwriter.plan.status'))->json('ideas.0');

        $this->assertSame('working', $planned['stage']);
        $this->assertFalse($planned['finished']);
        $this->assertStringContainsString('ghostwriter='.$session, $planned['resume_url']);

        // Remove the conversation and it is an idea again.
        $this->deleteJson(cp_route('ghostwriter.sessions.destroy', $session))->assertOk();
        $this->getJson(cp_route('ghostwriter.plan.status'))->assertJsonPath('ideas.0.status', 'open');
    }
}
