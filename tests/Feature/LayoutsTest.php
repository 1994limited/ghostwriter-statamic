<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftLayouts;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\RefreshLayouts;
use NineteenNinetyFour\Ghostwriter\Jobs\RunSessionTurn;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Layouts and extras in the writing panel (core's Arrange, design §3): the
 * first draft is the writer and then one call to the layout planner; the
 * cards, choosing one, Use this draft, the extras and Refresh layouts.
 * Every model reply here is the fake's.
 */
final class LayoutsTest extends TestCase
{
    public const DRAFT = "title: Winter garden care\npage_builder:\n  - type: hero\n    heading: Winter care for small gardens\n    intro: Four visits, and the garden is ready for spring.\n  - type: text\n    body: |-\n      We visit four times between November and February.\n\n      ## What we do\n\n      Cut back, mulch and protect the borders.\n\n      ## Where we work\n\n      We cover Northumberland, Durham and the Tyne Valley.\n  - type: cta\n    heading: Book your winter visits\n    button: Get in touch";

    // A count of a list the person gave (the writer miscounts it), and a number from the draft.
    public const EXTRAS = "<extras>\n- kind: stats\n  items:\n    - text: \"5 areas\"\n      value: \"5\"\n      label: areas\n      source: { from: answer, quote: \"Northumberland, Durham and the Tyne Valley\" }\n    - text: \"4 visits a winter\"\n      value: \"4\"\n      label: visits a winter\n      source: { from: draft, quote: \"We visit four times between November and February.\" }\n</extras>";

    public const PLANS = "<plans>\n- name: Numbers first\n  description: The numbers up top, then the visits\n  page_builder:\n    - type: hero\n      place: { heading: u2, intro: u3 }\n    - type: stats\n      place: { items: [x1.1, x1.2] }\n    - type: text\n      place: { body: [u4, u5, u6] }\n    - type: cta\n      place: { heading: u7, button: u8 }\n- name: A block a section\n  description: Each section in a text block of its own\n  page_builder:\n    - type: hero\n      place: { heading: u2, intro: u3 }\n    - type: text\n      place: { body: u4 }\n    - type: text\n      place: { body: u5 }\n    - type: text\n      place: { body: u6 }\n    - type: cta\n      place: { heading: u7, button: u8 }\n</plans>";

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

    public function test_the_first_draft_is_the_writer_then_the_planner_and_the_panel_shows_the_cards(): void
    {
        $session = $this->firstDraft();

        $this->assertSame(['writer', 'layout-planner'], array_map(fn (TextRequest $request) => $request->agent, $this->ai->requests()));
        $this->assertSame(['w', 'p1', 'p2'], array_column($session->plans, 'id'));
        $this->assertNull($session->plan);
        $this->assertFalse(DraftLayouts::isPlanning($session->id));

        $detail = app(Presenter::class)->detail($session);
        $cards = $detail['layouts']['plans'];

        $this->assertSame(['As written', 'Numbers first', 'A block a section'], array_column($cards, 'name'));
        $this->assertSame([3, 4, 5], array_column($cards, 'blocks'));
        $this->assertSame([false, true, false], array_column($cards, 'suggested'), 'the layout most like the site\'s pages');
        $this->assertSame('The numbers up top, then the visits', $cards[1]['description']);
        $this->assertSame(['Hero', 'Stats', 'Text', 'Call to action'], $cards[1]['outline']);
        $this->assertSame('w', $detail['layouts']['chosen']);
        $this->assertFalse($detail['layouts']['planning']);

        // The extras: the count is core's (3, not the writer's 5), marked
        // for review, and each says where it came from.
        $stats = $detail['extras'][0];
        $this->assertSame(['stats', 'Stats'], [$stats['kind'], $stats['label']]);
        // As stored, markers and all: the extras list shows the count to check as a chip.
        $this->assertSame(['[[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]]', '[[check: 3 | from: Northumberland, Durham and the Tyne Valley]]'], [$stats['items'][0]['text'], $stats['items'][0]['parts']['value']]);
        $this->assertSame(['needs-review', 'Needs review'], [$stats['items'][0]['state'], $stats['items'][0]['state_label']]);
        $this->assertSame('Counted from your answer: “Northumberland, Durham and the Tyne Valley”', $stats['items'][0]['count_label']);
        $this->assertSame(['answer', 'from your answer'], [$stats['items'][0]['source']['kind'], $stats['items'][0]['source']['label']]);
        $this->assertSame(['draft', 'from the draft', null], [$stats['items'][1]['source']['kind'], $stats['items'][1]['source']['label'], $stats['items'][1]['state']]);
        $this->assertStringContainsString('[[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]]', json_encode($session->extras, JSON_UNESCAPED_UNICODE));
    }

    public function test_while_the_planner_looks_the_draft_is_already_there(): void
    {
        $session = $this->startedSession();
        $seen = null;

        $this->ai->respond('writer', "<reply>Here it is.</reply>\n<draft>\n".self::DRAFT."\n</draft>\n".self::EXTRAS);
        $this->ai->respond('layout-planner', function () use ($session, &$seen) {
            $seen = app(Presenter::class)->detail($this->sessions()->find($session->id));

            return self::PLANS;
        });

        $this->runJob(new RunSessionTurn($session->id));

        $this->assertSame(self::DRAFT, $seen['draft'], 'the draft is saved before the planner is asked');
        $this->assertSame(Session::IDLE, $seen['status']);
        $this->assertTrue($seen['layouts']['planning']);
        $this->assertSame(['w'], array_column($seen['layouts']['plans'], 'id'));
        $this->assertFalse(app(Presenter::class)->detail($this->sessions()->find($session->id))['layouts']['planning']);
    }

    public function test_choosing_a_layout_is_shared_and_follows_through_blocks_and_use_this_draft(): void
    {
        $session = $this->firstDraft();
        $this->ai->reset();

        $this->patchJson(cp_route('ghostwriter.sessions.layout', $session->id), ['plan' => 'p1'])
            ->assertOk()
            ->assertJsonPath('layouts.chosen', 'p1')
            ->assertJsonPath('layouts.chosen_name', 'Numbers first')
            ->assertJsonPath('extras.0.use_label', 'Used in Numbers first')
            ->assertJsonPath('preview.1.items.1.label', 'Stats');

        $this->assertSame('p1', $this->sessions()->find($session->id)->plan);

        // Blocks follow the layout: writing that is one value of the draft
        // is still edited in place, by its place in the draft.
        $nodes = app(Presenter::class)->detail($this->sessions()->find($session->id))['preview'][1]['items'];
        $this->assertSame(['page_builder', 0, 'heading'], $nodes[0]['fields'][0]['path']);
        $this->assertTrue($nodes[0]['fields'][0]['editable']);
        $this->assertSame(['page_builder', 1, 'body'], $nodes[2]['fields'][0]['path']);
        $this->assertFalse($nodes[1]['fields'][0]['items'][0][0]['editable'], 'an extra is changed under Extras');
        $this->assertSame('3', $nodes[1]['fields'][0]['items'][0][0]['text']);

        $applied = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['values' => []])->assertOk()->json();
        $this->assertSame(['hero', 'stats', 'text', 'cta'], array_column($applied['values']['page_builder'], 'type'));
        $this->assertSame('[[check: 3 | from: Northumberland, Durham and the Tyne Valley]]', $applied['values']['page_builder'][1]['items'][0]['value']);
        $this->assertSame('4', $applied['values']['page_builder'][1]['items'][1]['value']);

        // The count still to check is a step in Finish this page.
        $this->assertContains('check', array_column($applied['gaps']['gaps'], 'kind'));

        // Back to the writer's own: apply is exactly what it was.
        $this->patchJson(cp_route('ghostwriter.sessions.layout', $session->id), ['plan' => 'w'])->assertOk()->assertJsonPath('layouts.chosen', 'w');
        $this->assertNull($this->sessions()->find($session->id)->plan);
        $this->assertSame(['hero', 'text', 'cta'], array_column($this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['values' => []])->json('values.page_builder'), 'type'));

        $this->patchJson(cp_route('ghostwriter.sessions.layout', $session->id), ['plan' => 'p9'])->assertStatus(422);
        $this->ai->assertNothingSent();
    }

    public function test_a_later_turn_calls_only_the_writer_and_a_hand_edit_can_leave_a_layout_to_refresh(): void
    {
        $session = $this->firstDraft();
        $this->patchJson(cp_route('ghostwriter.sessions.layout', $session->id), ['plan' => 'p2'])->assertOk();
        $this->ai->reset();

        $session = $this->sessions()->find($session->id);
        $session->addMessage('user', 'Say who it suits.');
        $session->status = Session::WORKING;
        $this->sessions()->save($session);

        $this->ai->respond('writer', "<reply>Added it.</reply>\n<draft>\n".str_replace('Cut back, mulch and protect the borders.', "Cut back, mulch and protect the borders.\n\n      ## Who it suits\n\n      Gardens with borders and no time.", self::DRAFT)."\n</draft>");
        $this->runJob(new RunSessionTurn($session->id));

        $this->assertSame(['writer'], array_map(fn (TextRequest $request) => $request->agent, $this->ai->requests()));
        $session = $this->sessions()->find($session->id);
        $this->assertSame(['w', 'p1', 'p2'], array_column($session->plans, 'id'));
        $this->assertSame('p2', $session->plan, 'the choice stays');
        $this->assertCount(2, $session->extras[0]['items'], 'extras stay when the writer sends none');
        $this->assertStringContainsString('Who it suits', json_encode($this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['values' => []])->json('values')));

        // Taking the hero's heading out by hand: the other layouts can't place it.
        $this->ai->reset();
        $this->patchJson(cp_route('ghostwriter.sessions.draft', $session->id), ['draft' => str_replace("    heading: Winter care for small gardens\n", '', $this->sessions()->find($session->id)->draft)])->assertOk();

        $detail = app(Presenter::class)->detail($this->sessions()->find($session->id));
        $this->assertTrue($detail['layouts']['stale']);
        $this->assertSame('w', $detail['layouts']['chosen'], 'a layout that needs refreshing isn\'t used');
        $this->patchJson(cp_route('ghostwriter.sessions.layout', $session->id), ['plan' => 'p1'])->assertStatus(422);
        $this->ai->assertNothingSent();

        // Refresh layouts: one planner call, in the background.
        $this->ai->respond('layout-planner', "<plans>\n- name: Numbers first\n  description: Numbers, then the words\n  page_builder:\n    - type: stats\n      place: { items: [x1.1, x1.2] }\n    - type: text\n      place: { body: [u3, u4, u5, u9, u6] }\n    - type: cta\n      place: { heading: u7, button: u8 }\n</plans>");

        $this->postJson(cp_route('ghostwriter.sessions.layouts.refresh', $session->id))->assertOk();
        $this->assertSame(['layout-planner'], array_map(fn (TextRequest $request) => $request->agent, $this->ai->requests()));

        $detail = app(Presenter::class)->detail($this->sessions()->find($session->id));
        $this->assertFalse($detail['layouts']['stale']);
        $this->assertFalse($detail['layouts']['planning']);
        $this->assertSame(['As written', 'Numbers first'], array_column($detail['layouts']['plans'], 'name'));
        $this->patchJson(cp_route('ghostwriter.sessions.layout', $session->id), ['plan' => 'p1'])->assertOk();
        $this->assertSame(['stats', 'text', 'cta'], array_column($this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['values' => []])->json('values.page_builder'), 'type'));
    }

    public function test_extras_are_edited_and_deleted_without_a_model_and_layouts_follow(): void
    {
        $session = $this->firstDraft();
        $this->patchJson(cp_route('ghostwriter.sessions.layout', $session->id), ['plan' => 'p1'])->assertOk();
        $this->ai->reset();

        // A new label, the count left as it was: still a count to check, and the layout still holds it.
        $list = 'Northumberland, Durham and the Tyne Valley';
        $this->patchJson(cp_route('ghostwriter.sessions.extras.update', [$session->id, 'x1.1']), ['text' => "[[check: 3 counties | from: {$list}]]", 'parts' => ['value' => "[[check: 3 | from: {$list}]]", 'label' => 'counties']])
            ->assertOk()
            ->assertJsonPath('extras.0.items.0.text', "[[check: 3 counties | from: {$list}]]")
            ->assertJsonPath('extras.0.items.0.state', 'needs-review')
            ->assertJsonPath('extras.0.items.0.source.label', 'from your answer')
            ->assertJsonPath('layouts.chosen', 'p1')
            ->assertJsonPath('layouts.stale', false);

        // Changed by the editor: their words, and no longer a count to check.
        $this->patchJson(cp_route('ghostwriter.sessions.extras.update', [$session->id, 'x1.1']), ['text' => '3 counties', 'parts' => ['value' => '3', 'label' => 'counties']])
            ->assertOk()
            ->assertJsonPath('extras.0.items.0.text', '3 counties')
            ->assertJsonPath('extras.0.items.0.source.label', 'your words')
            ->assertJsonPath('extras.0.items.0.state', null);

        $this->assertSame('3', $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['values' => []])->json('values.page_builder.1.items.0.value'));

        // Deleting both: the layout that used them goes on without a stats block.
        $this->deleteJson(cp_route('ghostwriter.sessions.extras.destroy', [$session->id, 'x1.1']))->assertOk();
        $this->deleteJson(cp_route('ghostwriter.sessions.extras.destroy', [$session->id, 'x1.2']))->assertOk()->assertJsonPath('extras', []);
        $this->deleteJson(cp_route('ghostwriter.sessions.extras.destroy', [$session->id, 'x1.2']))->assertStatus(422);

        $this->assertSame(['hero', 'text', 'cta'], array_column($this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['values' => []])->json('values.page_builder'), 'type'));
        $this->ai->assertNothingSent();
    }

    public function test_a_count_to_check_is_a_step_in_finish_this_page_and_blocks_publishing(): void
    {
        $session = $this->firstDraft();
        $this->patchJson(cp_route('ghostwriter.sessions.layout', $session->id), ['plan' => 'p1'])->assertOk();
        $values = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['values' => []])->json('values');
        $this->ai->reset();

        $check = fn () => collect($this->postJson(cp_route('ghostwriter.finish.check'), ['collection' => 'services', 'blueprint' => 'service', 'session' => $session->id, 'values' => $values])->assertOk()->json('gaps'))->firstWhere('kind', 'check');

        $gap = $check();
        $this->assertSame('page_builder.1.items.0.value', $gap['dotted']);
        $this->assertSame('blocks', $gap['severity']);
        $this->assertSame('I counted 3 from “Northumberland, Durham and the Tyne Valley”. Is that right?', $gap['message']);
        $this->assertSame([['confirm', 'Looks right', '3'], ['change', 'Change it', '3'], ['remove', 'Remove it', null]], array_map(fn (array $fix) => [$fix['action'], $fix['label'], $fix['value'] ?? null], $gap['fixes']));
        $this->assertSame('[[check: 3 | from: Northumberland, Durham and the Tyne Valley]]', $gap['meta']['match']);

        // The list given has changed since: the step says so, and offers the new count.
        $stored = $this->sessions()->find($session->id);
        $stored->answers = ['what' => 'Winter care. We cover Northumberland, Durham, Cumbria and the Tyne Valley.'];
        $stored->draft = str_replace('Durham and', 'Durham, Cumbria and', $stored->draft);
        $stored->messages = array_map(fn (array $message) => $message['role'] === 'user' ? ['content' => str_replace('Durham and', 'Durham, Cumbria and', $message['content'])] + $message : $message, $stored->messages);
        $this->sessions()->save($stored);

        $gap = $check();
        $this->assertSame('changed', $gap['meta']['stale']);
        $this->assertStringContainsString('It now has 4. Use “4” instead?', $gap['message']);
        $this->assertSame(['confirm', 'Use “4”', '4'], [$gap['fixes'][0]['action'], $gap['fixes'][0]['label'], $gap['fixes'][0]['value']]);

        // Published with the count still to check: refused, by its field.
        $entry = Entry::make()->collection('services')->blueprint('service')->slug('winter')->published(true)->data(['title' => 'Winter', 'page_builder' => [
            ['id' => 'st', 'type' => 'stats', 'enabled' => true, 'items' => [['id' => 'r', 'value' => '[[check: 3 | from: Northumberland, Durham and the Tyne Valley]]', 'label' => 'areas']]],
        ]]);

        try {
            $entry->save();
            $this->fail('A count to check was published.');
        } catch (ValidationException $refused) {
            $this->assertSame(['page_builder.0.items.0.value' => ['Check “3” before publishing.']], $refused->errors());
        }

        $entry->set('page_builder', [['id' => 'st', 'type' => 'stats', 'enabled' => true, 'items' => [['id' => 'r', 'value' => '3', 'label' => 'areas']]]])->save();
        $this->assertNotNull(Entry::find($entry->id()));
        $this->ai->assertNothingSent();
    }

    public function test_each_card_can_be_previewed_for_its_thumbnail(): void
    {
        $session = $this->firstDraft();
        $this->ai->reset();

        $chosen = $this->postJson(cp_route('ghostwriter.sessions.preview', $session->id), [])->assertOk()->json();
        $p1 = $this->postJson(cp_route('ghostwriter.sessions.preview', $session->id), ['plan' => 'p1'])->assertOk()->json();
        $again = $this->postJson(cp_route('ghostwriter.sessions.preview', $session->id), ['plan' => 'p1'])->assertOk()->json();

        $this->assertNotSame($chosen['hash'], $p1['hash']);
        $this->assertTrue($again['cached'], 'the same layout reuses its render');
        $this->assertSame($p1['url'], $again['url']);
        $this->assertContains('Stats', array_column($p1['map'], 'label'));

        $html = $this->get(parse_url($p1['url'], PHP_URL_PATH).'?'.parse_url($p1['url'], PHP_URL_QUERY))->assertOk()->getContent();
        $this->assertStringContainsString('class="stats"', $html);
        // The marker is printed as it is; the panel shows it as a chip in the frame.
        $this->assertStringContainsString('[[check: 3 | from: Northumberland, Durham and the Tyne Valley]]', $html);
        $this->ai->assertNothingSent();
    }

    public function test_a_piece_with_little_to_arrange_asks_the_planner_nothing(): void
    {
        $session = $this->startedSession();
        $this->ai->respond('writer', "<reply>Here it is.</reply>\n<draft>\ntitle: Short\npage_builder:\n  - type: hero\n    heading: Just a hero\n</draft>");

        $this->runJob(new RunSessionTurn($session->id));

        $this->assertSame(['writer'], array_map(fn (TextRequest $request) => $request->agent, $this->ai->requests()));
        $detail = app(Presenter::class)->detail($this->sessions()->find($session->id));
        $this->assertCount(1, $detail['layouts']['plans']);
        $this->assertNull($detail['layouts']['chosen_name']);
    }

    public function test_when_the_planner_fails_the_draft_keeps_the_writers_layout(): void
    {
        $session = $this->startedSession();
        $this->ai->respond('writer', "<reply>Here it is.</reply>\n<draft>\n".self::DRAFT."\n</draft>");
        $this->ai->respond('layout-planner', 'I would rather not.');

        $this->runJob(new RunSessionTurn($session->id));

        $session = $this->sessions()->find($session->id);
        $this->assertSame(self::DRAFT, $session->draft);
        $this->assertSame(Session::IDLE, $session->status);
        $this->assertSame(['w'], array_column($session->plans, 'id'));
        $this->assertFalse(DraftLayouts::isPlanning($session->id));
    }

    public function test_refresh_is_refused_while_the_planner_is_already_looking(): void
    {
        $session = $this->firstDraft();
        DraftLayouts::planning($session->id);

        $this->postJson(cp_route('ghostwriter.sessions.layouts.refresh', $session->id))->assertStatus(409);

        DraftLayouts::planned($session->id);
        $this->ai->reset()->respond('layout-planner', '<plans>[]</plans>');
        $this->runJob(new RefreshLayouts($session->id));
        $this->assertSame(['w'], array_column($this->sessions()->find($session->id)->plans, 'id'));
    }

    public function test_editing_an_entry_under_another_layout_keeps_its_bard_sets_by_the_words_they_followed(): void
    {
        $this->signInWith(['access ghostwriter', 'view services entries', 'edit services entries', 'create services entries']);

        // The text block's rich text can hold a photo, which no draft holds.
        $blueprint = Blueprint::find('collections.services.service');
        $fields = $blueprint->fields()->items()->all();
        $fields[1]['field']['sets']['main']['sets']['text']['fields'][0]['field']['sets'] = ['main' => ['sets' => [
            'photo' => ['display' => 'Photo', 'fields' => [['handle' => 'image', 'field' => ['type' => 'text']]]],
        ]]];
        $blueprint->setContents(['fields' => $fields])->save();

        $paragraph = fn (string $text) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        $heading = fn (string $text) => ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $text]]];

        $entry = Entry::make()->collection('services')->slug('winter')->published(true)->data(['title' => 'Winter garden care', 'page_builder' => [
            ['id' => 'hw', 'type' => 'hero', 'enabled' => true, 'heading' => 'Winter care for small gardens', 'intro' => 'Four visits, and the garden is ready for spring.'],
            ['id' => 'tw', 'type' => 'text', 'enabled' => true, 'body' => [
                $paragraph('We visit four times between November and February.'),
                ['type' => 'set', 'attrs' => ['id' => 'ph', 'values' => ['type' => 'photo', 'image' => 'winter/borders.jpg']]],
                $heading('What we do'),
                $paragraph('Cut back, mulch and protect the borders.'),
                $heading('Where we work'),
                $paragraph('We cover Northumberland, Durham and the Tyne Valley.'),
            ]],
            ['id' => 'cw', 'type' => 'cta', 'enabled' => true, 'heading' => 'Book your winter visits', 'button' => 'Get in touch'],
        ]]);
        $entry->save();

        $detail = $this->postJson(cp_route('ghostwriter.entries.session', $entry->id()))->assertOk()->json();
        $this->assertStringNotContainsString('borders.jpg', $detail['draft']);

        // An edit to the hero, then a layout with "Where we work" first: the
        // first text block, the one matched to the entry's, no longer starts
        // with the paragraph the photo followed.
        $this->ai->respond('writer', "<reply>Done.</reply>\n<draft>\n".str_replace('Four visits, and the garden is ready for spring.', 'Four visits, and spring takes care of itself.', $detail['draft'])."\n</draft>");
        $this->postJson(cp_route('ghostwriter.sessions.message', $detail['id']), ['message' => 'A warmer intro.'])->assertOk();
        $this->runJob(new RunSessionTurn($detail['id']));

        $this->ai->respond('layout-planner', "<plans>\n- name: Where first\n  description: Where we work, then the rest\n  page_builder:\n    - type: hero\n      place: { heading: u2, intro: u9 }\n    - type: text\n      place: { body: u6 }\n    - type: text\n      place: { body: [u4, u5] }\n    - type: cta\n      place: { heading: u7, button: u8 }\n</plans>");
        $this->postJson(cp_route('ghostwriter.sessions.layouts.refresh', $detail['id']))->assertOk();
        $this->patchJson(cp_route('ghostwriter.sessions.layout', $detail['id']), ['plan' => 'p1'])->assertOk();

        $blocks = $this->postJson(cp_route('ghostwriter.sessions.apply', $detail['id']), ['values' => []])->assertOk()->json('values.page_builder');
        $shape = fn (array $block) => array_map(fn (array $node) => $node['type'] === 'set' ? $node['attrs']['values']['type'] : $node['type'], $block['body']);

        $this->assertSame(['hero', 'text', 'text', 'cta'], array_column($blocks, 'type'));
        $this->assertSame('Four visits, and spring takes care of itself.', $blocks[0]['intro']);
        $this->assertSame(['heading', 'paragraph'], $shape($blocks[1]));
        $this->assertSame(['paragraph', 'photo', 'heading', 'paragraph'], $shape($blocks[2]), 'the photo follows its paragraph into the second text block');
        $this->assertSame(['ph', 'winter/borders.jpg'], [$blocks[2]['body'][1]['attrs']['id'], $blocks[2]['body'][1]['attrs']['values']['image']]);
        $this->assertSame('Book your winter visits', $blocks[3]['heading']);
        $this->assertSame(1, substr_count(json_encode($blocks), 'borders.jpg'), 'kept once');
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
