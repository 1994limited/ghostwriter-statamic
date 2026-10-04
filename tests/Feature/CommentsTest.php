<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\ApplyComments;
use NineteenNinetyFour\Ghostwriter\Jobs\RunSessionTurn;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Comments on the draft in the Preview (design §9, core's SessionReview):
 * adding them on a block or on some words, Apply as one reviser call in
 * the background, the before and after, Put it back, resolving and
 * reopening, a refused change and a stale copy. Every model reply is the
 * fake's.
 */
final class CommentsTest extends TestCase
{
    private const DRAFT = LayoutsTest::DRAFT;

    private const EXTRAS = LayoutsTest::EXTRAS;

    private const PLANS = LayoutsTest::PLANS;

    protected function setUp(): void
    {
        parent::setUp();

        $views = $this->workspace.'/views';
        File::ensureDirectoryExists($views);
        File::put($views.'/layout.antlers.html', '<!doctype html><html><body>{{ template_content }}</body></html>');
        File::put($views.'/service.antlers.html', '<main>{{ page_builder }}<section class="{{ type }}">{{ heading }}{{ body }}{{ items }}<b>{{ value }}</b> {{ label }}{{ /items }}{{ button }}</section>{{ /page_builder }}</main>');
        View::addLocation($views);

        Collection::make('services')->title('Services')->routes('/services/{slug}')->template('service')->save();
        Blueprint::make('service')->setNamespace('collections.services')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'page_builder', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                'hero' => ['display' => 'Hero', 'fields' => [['handle' => 'heading', 'field' => ['type' => 'text', 'validate' => ['required']]], ['handle' => 'intro', 'field' => ['type' => 'textarea']]]],
                'text' => ['display' => 'Text', 'fields' => [['handle' => 'body', 'field' => ['type' => 'bard']]]],
                'stats' => ['display' => 'Stats', 'fields' => [['handle' => 'items', 'field' => ['type' => 'grid', 'fields' => [
                    ['handle' => 'value', 'field' => ['type' => 'text']],
                    ['handle' => 'label', 'field' => ['type' => 'text']],
                ]]]]],
                'cta' => ['display' => 'Call to action', 'fields' => [['handle' => 'heading', 'field' => ['type' => 'text']], ['handle' => 'button', 'field' => ['type' => 'text']]]],
            ]]]]],
        ]])->save();

        // The site's pages put their numbers near the top.
        foreach (['Lawns', 'Ponds', 'Hedges'] as $i => $title) {
            Entry::make()->collection('services')->slug(strtolower($title))->published(true)->data(['title' => $title, 'page_builder' => [
                ['id' => "h{$i}", 'type' => 'hero', 'enabled' => true, 'heading' => $title.' all year', 'intro' => 'Looked after for you, '.strtolower($title).' and all.'],
                ['id' => "s{$i}", 'type' => 'stats', 'enabled' => true, 'items' => [['id' => "r{$i}", 'value' => (string) ($i + 2), 'label' => $title.' visits']]],
                ['id' => "t{$i}", 'type' => 'text', 'enabled' => true, 'body' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'About '.$title.'.']]]]],
                ['id' => "c{$i}", 'type' => 'cta', 'enabled' => true, 'heading' => 'Book '.strtolower($title), 'button' => 'Ask about '.strtolower($title)],
            ]])->save();
        }

        app(TypeRepository::class)->save(TypeRepository::make('services', [
            'title' => 'Service',
            'collection' => 'services',
            // A kind of one blueprint: reading its entries mustn't leave the preview without its collection.
            'blueprint' => 'service',
            'questions' => [['handle' => 'what', 'label' => 'What is it?', 'type' => 'textarea', 'required' => true]],
        ]));
    }

    public function test_apply_sends_one_message_and_one_call_answers_each_comment(): void
    {
        $session = $this->firstDraft();
        $this->ai->reset();
        Bus::fake([ApplyComments::class, RunSessionTurn::class]);

        $this->ai->respond('reviser', "<changes>\n- comment: 1\n  reply: Warmer, same offer.\n  units:\n    u7: Book your winter visits with us\n- comment: 2\n  reply: Said when.\n  replace:\n    - unit: u5\n      exact: \"Cut back, mulch and protect the borders.\"\n      with: \"Cut back, mulch in November and protect the borders.\"\n- comment: 3\n  reply: I can’t change a photo from a comment.\n- comment: 4\n  reply: Added the price.\n  units:\n    u4: We visit four times between November and February, for £75 a visit.\n</changes>");

        $started = $this->apply($session, [
            ['kind' => 'block', 'units' => ['u7', 'u8'], 'label' => 'Call to action', 'path' => 'page_builder/#c', 'body' => 'Make the heading warmer.'],
            ['kind' => 'text', 'units' => ['u4', 'u5', 'u6'], 'label' => 'Text', 'quote' => ['exact' => 'Cut back, mulch and protect the borders.', 'prefix' => 'What we do ', 'suffix' => ' Where'], 'body' => 'Say <b>when</b> we mulch.'],
            ['kind' => 'page', 'body' => 'Is the photo right?'],
            ['kind' => 'block', 'units' => ['u4'], 'label' => 'Text', 'body' => 'Say it is £60 a visit.'],
        ])->assertOk()->json();

        Bus::assertDispatched(ApplyComments::class);
        $this->assertSame('working', $started['status']);
        $message = end($started['messages']);
        $this->assertSame(['user', [1, 2, 3, 4]], [$message['role'], array_column($message['comments']['items'], 'number')]);
        $this->assertSame(['u5'], $message['comments']['items'][1]['scope']['units'], 'words are anchored to the one unit that holds them');
        $this->assertSame(['sending', 'sending', 'sending', 'sending'], array_column($started['comments']['pins'], 'status'));
        $this->assertSame(5, $started['comments']['next']);

        // While it runs: Send is refused, and so is a second Apply.
        $this->postJson(cp_route('ghostwriter.sessions.message', $session->id), ['message' => 'Hello'])->assertStatus(409);
        $this->apply($session, [['kind' => 'page', 'body' => 'And shorter.']])->assertStatus(409);

        $this->runJob(new ApplyComments($session->id));

        $this->assertSame(['reviser'], array_map(fn (TextRequest $request) => $request->agent, $this->ai->requests()));
        $detail = app(Presenter::class)->detail($this->sessions()->find($session->id));
        $this->assertSame('idle', $detail['status']);
        $pins = $detail['comments']['pins'];
        $this->assertSame(['changed', 'changed', 'replied', 'refused'], array_column($pins, 'status'));
        $this->assertSame(['Changed', 'Changed', 'Replied', 'Not applied'], array_column($pins, 'state'));
        $this->assertStringContainsString('Book your winter visits with us', $detail['draft']);
        $this->assertStringContainsString('mulch in November', $detail['draft']);
        $this->assertStringNotContainsString('£75', $detail['draft'], 'a figure nobody gave is refused');
        $this->assertStringContainsString('£75', (string) $pins[3]['reply'], 'the reason it was refused');
        $this->assertSame([['=', 'Book your winter '], ['+', 'visits with us'], ['-', 'visits']], $pins[0]['changes'][0]['diff']);
        $this->assertSame('<p>Warmer, same offer.</p>', $pins[0]['reply']);
        $this->assertSame('Say <b>when</b> we mulch.', $pins[1]['body'], 'the words as written; the panel escapes them');
        $answer = end($detail['messages']);
        $this->assertSame(['assistant', $pins[0]['answer']], [$answer['role'], $answer['index']], 'the chat finds each result by the answer\'s place in the conversation');
        $this->assertCount(4, $answer['comments']['results']);

        // Put it back, resolve, reopen: no model.
        $this->ai->reset();
        $back = $this->postJson(cp_route('ghostwriter.sessions.comments.put_back', [$session->id, $pins[0]['answer'], 1]))->assertOk()->json();
        $this->assertStringNotContainsString('with us', $back['draft']);
        $this->assertSame(['changed', 'You', false], [$back['comments']['pins'][0]['status'], $back['comments']['pins'][0]['put_back_by'], $back['comments']['pins'][0]['can_put_back']]);
        $this->postJson(cp_route('ghostwriter.sessions.comments.put_back', [$session->id, $pins[0]['answer'], 1]))->assertStatus(409);

        $resolved = $this->postJson(cp_route('ghostwriter.sessions.comments.resolve', [$session->id, $pins[0]['answer'], 1]))->assertOk()->json();
        $this->assertSame(['resolved', 'You'], [$resolved['comments']['pins'][0]['status'], $resolved['comments']['pins'][0]['resolved_by']]);
        $this->postJson(cp_route('ghostwriter.sessions.comments.resolve', [$session->id, $pins[0]['answer'], 1]), ['resolved' => false])->assertOk()
            ->assertJsonPath('comments.pins.0.status', 'changed');
        $this->postJson(cp_route('ghostwriter.sessions.comments.resolve', [$session->id, $pins[0]['answer'], 9]))->assertNotFound();

        // In another layout, the same comments sit on its blocks.
        $this->patchJson(cp_route('ghostwriter.sessions.layout', $session->id), ['plan' => 'p2'])->assertOk()
            ->assertJsonPath('comments.pins.0.blocks', ['page_builder/4']);

        $this->ai->assertNothingSent();
    }

    public function test_a_run_that_fails_answers_every_comment_and_frees_the_piece(): void
    {
        $session = $this->firstDraft();
        Bus::fake([ApplyComments::class]);
        $this->ai->respond('reviser', fn () => throw new ProviderException('The provider is busy.'));

        $this->apply($session, [['kind' => 'block', 'units' => ['u7'], 'label' => 'Call to action', 'body' => 'Warmer.']])->assertOk();
        $this->runJob(new ApplyComments($session->id));

        $detail = app(Presenter::class)->detail($this->sessions()->find($session->id));
        $this->assertSame(['idle', 'failed', 'Not applied'], [$detail['status'], $detail['comments']['pins'][0]['status'], $detail['comments']['pins'][0]['state']]);
        $this->assertStringContainsString('The provider is busy.', (string) $detail['comments']['pins'][0]['reply']);

        // A job that never finished is answered once, by the worker giving up.
        $this->apply($session, [['kind' => 'page', 'body' => 'Shorter.']])->assertOk();
        (new ApplyComments($session->id))->failed();
        $this->assertSame(['failed', 'failed'], array_column(app(Presenter::class)->detail($this->sessions()->find($session->id))['comments']['pins'], 'status'));
    }

    public function test_a_piece_from_before_comments_takes_comments(): void
    {
        // An entry being edited in conversation, saved before comments (or
        // layouts) existed: no unit ids, no plans.
        Bus::fake([ApplyComments::class]);
        $session = $this->startedSession();
        $session->draft = self::DRAFT;
        $session->status = Session::IDLE;
        $session->editing = true;
        $this->sessions()->save($session);
        $this->assertSame([[], []], [$session->units, $session->plans]);
        $this->assertSame(['next' => 1, 'pins' => []], app(Presenter::class)->detail($session)['comments']);

        // Words selected on the page, as the frame gives them: whatever invisible marker came along is dropped.
        $marker = "\u{E0067}\u{E0077}\u{E0062}\u{E0032}\u{E007F}";
        $detail = $this->apply($session, [
            ['kind' => 'block', 'units' => ['u2', 'u3'], 'label' => 'Hero', 'path' => 'page_builder/#x', 'plan_path' => 'page_builder/0', 'plan' => null, 'quote' => null, 'body' => 'Warmer, please.'],
            ['kind' => 'text', 'units' => ['u4', 'u5', 'u6'], 'label' => 'Text', 'quote' => ['exact' => "Cut back, mulch and protect the borders.{$marker}", 'prefix' => '', 'suffix' => ''], 'body' => "Say when.{$marker}"],
        ])->assertOk()->json();

        $this->assertSame([['u2', 'u3'], ['u5']], array_column($detail['comments']['pins'], 'units'));
        $this->assertSame([true, true], array_column($detail['comments']['pins'], 'in_layout'), 'no layouts yet: its words are on the page');
        $this->assertSame('Cut back, mulch and protect the borders.', $detail['comments']['pins'][1]['quote']);
        $this->assertSame('Say when.', $detail['comments']['pins'][1]['body']);
    }

    public function test_a_comment_refused_says_why_in_words_next_to_it(): void
    {
        $session = $this->firstDraft();
        $apply = fn (array $comments) => $this->apply($session, $comments);
        $ok = ['kind' => 'block', 'units' => ['u2'], 'body' => 'Fine.'];

        $apply([])->assertStatus(422)->assertJsonPath('errors.comments.0', 'There are no comments to apply.');
        $apply([$ok, ['kind' => 'block', 'units' => ['u2'], 'body' => '']])->assertStatus(422)->assertJsonPath('errors', ['comments.1.body' => ['Write the comment first.']]);
        $apply([['kind' => 'block', 'units' => ['u2'], 'body' => "  \n "]])->assertStatus(422)->assertJson(['errors' => ['comments.0.body' => ['Write the comment first.']]]);
        $apply([['kind' => 'block', 'units' => ['u2'], 'body' => str_repeat('a', 2001)]])->assertStatus(422)->assertJson(['errors' => ['comments.0.body' => ['A comment can be at most 2000 characters.']]]);
        $apply([['units' => ['u2'], 'body' => 'Hm.']])->assertStatus(422)->assertJson(['errors' => ['comments.0.kind' => ['Say what the comment is on: a block, some words or the whole page.']]]);
        $apply([$ok, ['kind' => 'block', 'units' => ['u99'], 'body' => 'Hm.']])->assertStatus(422)->assertJson(['errors' => ['comments.1' => ['That block has no writing of its own to comment on. Comment on the whole page instead.']]]);
        $apply(array_fill(0, 13, $ok))->assertStatus(422)->assertJsonPath('errors.comments.0', 'Apply at most 12 comments at a time.');
        $this->postJson(cp_route('ghostwriter.sessions.comments.apply', 'nope'), ['comments' => [$ok]])->assertNotFound();
        $this->assertFalse($this->sessions()->find($session->id)->isWorking());
    }

    /**
     * @param  list<array<string, mixed>>  $comments
     */
    private function apply(Session $session, array $comments): TestResponse
    {
        return $this->postJson(cp_route('ghostwriter.sessions.comments.apply', $session->id), ['comments' => $comments]);
    }

    /**
     * A first draft written through the turn job, with extras and the
     * planner's two layouts.
     */
    private function firstDraft(): Session
    {
        $session = $this->startedSession();

        $this->ai->respond('writer', "<reply>Here it is.</reply>\n<draft>\n".self::DRAFT."\n</draft>\n".self::EXTRAS);
        $this->ai->respond('layout-planner', self::PLANS);

        $this->runJob(new RunSessionTurn($session->id));

        return $this->sessions()->find($session->id);
    }

    private function startedSession(): Session
    {
        $user = $this->signInWith(['access ghostwriter', 'view services entries', 'edit services entries', 'create services entries']);

        $type = app(TypeRepository::class)->find('services');
        $session = $this->makeSession('services', ['what' => 'Winter care. We cover Northumberland, Durham and the Tyne Valley.'], $user->id());
        $session->addMessage('user', app(Studio::class)->brief($type, $session));
        $session->status = Session::WORKING;

        return $this->sessions()->save($session);
    }
}
