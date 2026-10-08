<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftLayouts;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\RunSessionTurn;
use NineteenNinetyFour\Ghostwriter\Tests\Concerns\LinkSites;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

/**
 * Internal links on Statamic (SEO layer row 4): a first draft is linked to
 * the site's other pages between the writer and the planner, with the
 * panel showing "Checking headings and links…" meanwhile; the links are
 * Bard's `statamic://entry::id`; the panel lists them for the Text tab's
 * marks; Remove link keeps the words. Every model reply is the fake's.
 */
final class InternalLinksTest extends TestCase
{
    use LinkSites;

    private const DRAFT = <<<'YAML'
        title: Winter garden care
        body: |-
          Winter is when a garden is set up for the year ahead, and a little care now saves a lot of work in spring. Most borders need less than people think, and the jobs that matter are few and simple. We cut back only what has finished and would rot or smother the plants beneath it, and we leave seed heads standing for the birds.

          A thick layer of our own compost goes on every bed once the ground is wet and before it freezes. It keeps the roots warm, holds the moisture in and feeds the soil slowly through the winter. Tender plants in pots move against a south wall or into a cold greenhouse, wrapped in fleece on the coldest nights.

          ## Booking a visit

          We look after gardens across Northumberland, Durham and the Tyne Valley from November to February. If you would like a winter visit, tell us about your garden and we will arrange a first walk round before the cold sets in. Every visit ends with a short note of what we did and what we will do next time.
        YAML;

    protected function setUp(): void
    {
        parent::setUp();

        $this->linkSites();
        config(['ghostwriter.collections' => ['site_pages', 'visits']]);
        $this->saveEntry('contact', 'site_info', 'Contact us', ['summary' => 'Tell us about your garden and book a winter visit in Northumberland.']);

        Collection::make('visits')->title('Visits')->routes('/visits/{slug}')->save();
        Blueprint::make('visit')->setNamespace('collections.visits')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'buttons' => ['h2', 'h3', 'bold', 'italic', 'anchor']]],
        ]])->save();

        app(TypeRepository::class)->save(TypeRepository::make('visits', [
            'title' => 'Visit',
            'collection' => 'visits',
            'questions' => [['handle' => 'what', 'label' => 'What is it?', 'type' => 'textarea', 'required' => true]],
        ]));
    }

    protected function tearDown(): void
    {
        $this->tearDownLinkSites();

        parent::tearDown();
    }

    public function test_a_first_draft_is_linked_between_the_writer_and_the_planner(): void
    {
        $session = $this->startedSession();
        $seen = null;

        $this->ai->respond('writer', "<reply>Here it is.</reply>\n<draft>\n".self::DRAFT."\n</draft>");
        $this->ai->respond('seo-editor', function () use ($session, &$seen) {
            $seen = app(Presenter::class)->detail($this->sessions()->find($session->id));

            return json_encode(['notes' => 'Winter care; Contact fits the booking call.', 'links' => [
                ['unit' => 'u3', 'exact' => 'tell us about your garden', 'prefix' => '', 'target' => 'e1', 'hint' => '', 'why' => 'An invitation to get in touch.'],
            ]]);
        });
        $this->ai->respondStructured('seo-verifier', ['verdicts' => [['notes' => 'Right page.', 'id' => 'l1', 'verdict' => 'keep', 'reason' => 'Fits.']]]);
        $this->ai->respondStructured('layout-planner', ['plans' => []]);

        $this->runJob(new RunSessionTurn($session->id));
        $session = $this->sessions()->find($session->id);

        $agents = array_map(fn (TextRequest $request) => $request->agent, $this->ai->requests());
        $this->assertSame(['writer', 'seo-editor', 'seo-verifier'], array_slice($agents, 0, 3));
        $this->assertNotContains('seo-editor', array_slice($agents, 3), 'One of each per first draft.');

        // While the links were looked for, the draft was there to read, and the panel said so.
        $this->assertTrue($seen['seo']['checking']);
        $this->assertStringNotContainsString('statamic://', (string) $seen['draft']);

        $body = (string) Draft::parse((string) $session->draft)->data['body'];
        $this->assertStringContainsString('If you would like a winter visit, [tell us about your garden](statamic://entry::contact) and we will', $body);
        $this->assertStringContainsString('Contact us (Information)', $this->ai->prompted('seo-editor')[0]->prompt, 'A page outside Ghostwriter\'s collections is a candidate (decision 9).');

        $detail = app(Presenter::class)->detail($session);
        $this->assertFalse($detail['seo']['checking']);
        $this->assertFalse(DraftLayouts::isChecking($session->id));
        $this->assertSame('I linked to one of your pages: Contact us.', $detail['seo']['notice']);
        $this->assertSame(['tell us about your garden', 'statamic://entry::contact', 'Contact us', 'Information', '/contact'], [
            $detail['seo']['links'][0]['words'], $detail['seo']['links'][0]['href'], $detail['seo']['links'][0]['title'], $detail['seo']['links'][0]['type'], $detail['seo']['links'][0]['open_url'],
        ]);
    }

    public function test_the_links_are_on_the_piece_before_it_is_usable_while_the_planner_works(): void
    {
        $session = $this->startedSession();
        $planning = null;
        $applied = null;

        $this->ai->respond('writer', "<reply>Here it is.</reply>\n<draft>\n".self::DRAFT."\n</draft>");
        $this->ai->respondStructured('seo-editor', ['notes' => '…', 'links' => [['unit' => 'u3', 'exact' => 'tell us about your garden', 'prefix' => '', 'target' => 'e1', 'hint' => '', 'why' => '…']]]);
        $this->ai->respondStructured('seo-verifier', ['verdicts' => [['notes' => '…', 'id' => 'l1', 'verdict' => 'keep', 'reason' => '…']]]);
        $this->ai->respond('layout-planner', function () use ($session, &$planning, &$applied) {
            // "Finding other layouts…": the draft can be used, and it has its links.
            $planning = app(Presenter::class)->detail($this->sessions()->find($session->id));
            $applied = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['values' => []])->assertOk()->json();

            return json_encode(['plans' => []]);
        });

        $this->runJob(new RunSessionTurn($session->id));

        $this->assertNotNull($planning, 'The planner was asked.');
        $this->assertTrue($planning['layouts']['planning']);
        $this->assertFalse($planning['seo']['checking']);
        $this->assertStringContainsString('[tell us about your garden](statamic://entry::contact)', (string) $planning['draft']);
        $this->assertSame('I linked to one of your pages: Contact us.', $planning['seo']['notice']);
        $this->assertStringContainsString('statamic://entry::contact', json_encode($applied['values'], JSON_UNESCAPED_SLASHES), 'Used while the planner works, the draft has its links.');

        // Laid once: still there when the planner's answer is in.
        $session = $this->sessions()->find($session->id);
        $this->assertStringContainsString('[tell us about your garden](statamic://entry::contact)', (string) $session->draft);
        $this->assertCount(1, SeoState::of($session)->links);
    }

    public function test_use_this_draft_is_refused_while_the_links_are_checked(): void
    {
        $session = $this->startedSession();
        $refused = null;

        $this->ai->respond('writer', "<reply>Here it is.</reply>\n<draft>\n".self::DRAFT."\n</draft>");
        $this->ai->respond('seo-editor', function () use ($session, &$refused) {
            $refused = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['values' => []])->status();

            return json_encode(['notes' => '…', 'links' => []]);
        });
        $this->ai->respondStructured('layout-planner', ['plans' => []]);

        $this->runJob(new RunSessionTurn($session->id));

        $this->assertSame(409, $refused);
    }

    public function test_remove_link_keeps_the_words_and_nothing_else_changes(): void
    {
        $session = $this->startedSession();
        $this->ai->respond('writer', "<reply>Here it is.</reply>\n<draft>\n".self::DRAFT."\n</draft>");
        $this->ai->respondStructured('seo-editor', ['notes' => '…', 'links' => [['unit' => 'u3', 'exact' => 'tell us about your garden', 'prefix' => '', 'target' => 'e1', 'hint' => '', 'why' => '…']]]);
        $this->ai->respondStructured('seo-verifier', ['verdicts' => [['notes' => '…', 'id' => 'l1', 'verdict' => 'keep', 'reason' => '…']]]);
        $this->ai->respondStructured('layout-planner', ['plans' => []]);
        $this->runJob(new RunSessionTurn($session->id));
        $this->ai->reset();

        $this->postJson(cp_route('ghostwriter.sessions.links.remove', $session->id), ['href' => 'statamic://entry::nothing'])->assertStatus(422);
        $response = $this->postJson(cp_route('ghostwriter.sessions.links.remove', $session->id), ['href' => 'statamic://entry::contact'])->assertOk();

        $this->assertSame([], $response->json('seo.links'));
        $this->assertStringContainsString('If you would like a winter visit, tell us about your garden and we will', (string) $response->json('draft'));
        $this->assertSame(['statamic://entry::contact'], SeoState::of($this->sessions()->find($session->id))->removed);
        $this->ai->assertNothingSent();
    }

    private function startedSession(): Session
    {
        $user = $this->signInWith(['access ghostwriter', 'view visits entries', 'edit visits entries', 'create visits entries']);
        $type = app(TypeRepository::class)->find('visits');
        $session = $this->makeSession('visits', ['what' => 'Winter care for established gardens.'], $user->id());
        $session->addMessage('user', app(Studio::class)->brief($type, $session));
        $session->status = Session::WORKING;

        return $this->sessions()->save($session);
    }
}
