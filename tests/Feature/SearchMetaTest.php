<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SearchMeta;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoProvenance;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftLayouts;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\RunSessionTurn;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * The search title, description and address on Statamic (SEO layer row 5):
 * the first draft's SEO call writes the description and the address
 * follows the title; the Text tab's Search section shows them, takes the
 * editor's edits and Try again; "Use this draft" puts them in the form's
 * SEO fields and slug, never over a person's text or a published entry's
 * address; and Finish this page offers the draft's description where the
 * entry's own is too short. Every model reply is the fake's.
 */
final class SearchMetaTest extends TestCase
{
    private const DRAFT = <<<'YAML'
        title: Winter garden care
        body: |-
          Winter is when a garden is set up for the year ahead, and a little care now saves a lot of work in spring. Most borders need less than people think, and the jobs that matter are few and simple. We cut back only what has finished and would rot or smother the plants beneath it, and we leave seed heads standing for the birds.

          A thick layer of our own compost goes on every bed once the ground is wet and before it freezes. It keeps the roots warm, holds the moisture in and feeds the soil slowly through the winter. Tender plants in pots move against a south wall or into a cold greenhouse, wrapped in fleece on the coldest nights.

          ## Booking a visit

          We look after gardens across Northumberland, Durham and the Tyne Valley from November to February. If you would like a winter visit, tell us about your garden and we will arrange a first walk round before the cold sets in.
        YAML;

    /** 120 to 155 characters, only what the draft says. */
    private const DESCRIPTION = 'Winter care for gardens across Northumberland, Durham and the Tyne Valley: what we cut back, how we mulch, and how tender plants are kept warm.';

    private const ANOTHER = 'How we look after gardens in winter: cutting back what has finished, a thick layer of compost on every bed, and tender plants kept warm in fleece.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ghostwriter.collections' => ['services']]);

        Collection::make('services')->title('Services')->routes('/services/{slug}')->save();
        Blueprint::make('service')->setNamespace('collections.services')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'buttons' => ['h2', 'h3', 'bold', 'italic']]],
            ['handle' => 'seo_title', 'field' => ['type' => 'text', 'display' => 'SEO title', 'character_limit' => 60]],
            ['handle' => 'meta_description', 'field' => ['type' => 'textarea', 'display' => 'Meta description', 'character_limit' => 160]],
        ]])->save();

        app(TypeRepository::class)->save(TypeRepository::make('services', [
            'title' => 'Service',
            'collection' => 'services',
            'questions' => [['handle' => 'what', 'label' => 'What is it?', 'type' => 'textarea', 'required' => true]],
        ]));
    }

    public function test_the_first_drafts_seo_call_writes_the_description_and_the_panel_shows_the_search_section(): void
    {
        $this->assertGreaterThanOrEqual(120, mb_strlen(self::DESCRIPTION));
        $this->assertLessThanOrEqual(155, mb_strlen(self::DESCRIPTION));

        $session = $this->startedSession();
        $this->firstDraft($session);

        $agents = array_map(fn (TextRequest $request) => $request->agent, $this->ai->requests());
        $this->assertSame(['writer', 'seo-editor', 'layout-planner'], $agents, 'No links to look for: the one SEO call is for the description.');

        $session = $this->sessions()->find($session->id);
        $meta = SeoState::of($session)->meta;
        $this->assertSame(self::DESCRIPTION, $meta->description);
        $this->assertSame('', $meta->title, 'The page title fits: the SEO title keeps using it (decision 12).');
        $this->assertSame('winter-garden-care', $meta->slug);

        $search = app(Presenter::class)->detail($session)['search'];
        $this->assertSame('Search', $search['strings']['heading']);
        $this->assertFalse($search['title']['own']);
        $this->assertSame('Uses the page title: “Winter garden care”', $search['title']['uses_text']);
        $this->assertSame('It fits, so your SEO settings keep using it.', $search['title']['note_text']);
        $this->assertSame(self::DESCRIPTION, $search['description']['text']);
        $this->assertSame([mb_strlen(self::DESCRIPTION), 160, false], [$search['description']['length'], $search['description']['limit'], $search['description']['out']]);
        $this->assertSame('Aim for 120 to 155 characters', $search['description']['range_text']);
        $this->assertSame('Only what the page says.', $search['description']['note_text']);
        $this->assertSame(['winter-garden-care', 'localhost/services/', true], [$search['address']['slug'], $search['address']['base'], $search['address']['editable']]);
        $this->assertSame('Set on this new page only. Published pages keep their address.', $search['address']['note_text']);
        $this->assertSame('Meta description', $search['via']['description']);
        $this->assertFalse($search['writing']);
    }

    public function test_use_this_draft_puts_the_description_and_the_address_into_a_new_entrys_form(): void
    {
        $session = $this->startedSession();
        $this->firstDraft($session);
        $this->ai->reset();

        $applied = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['blueprint' => 'service', 'values' => ['title' => '', 'slug' => '', 'meta_description' => null]])->assertOk()->json();

        $this->assertSame(self::DESCRIPTION, $applied['values']['meta_description']);
        $this->assertSame('winter-garden-care', $applied['values']['slug']);
        $this->assertArrayNotHasKey('seo_title', $applied['values'], 'The page title fits: no SEO title of its own.');
        $this->assertNotContains('seo-missing', array_column($applied['gaps']['gaps'], 'kind'));
        $this->assertTrue(SeoState::of($this->sessions()->find($session->id))->written->owns(SeoField::DESCRIPTION, self::DESCRIPTION), 'Known as Ghostwriter\'s own from now on.');
        $this->assertSame(0, Entry::query()->where('collection', 'services')->count(), 'Nothing is saved.');
        $this->ai->assertNothingSent();

        // A slug someone typed in the form stays.
        $typed = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['blueprint' => 'service', 'values' => ['title' => 'Winter garden care', 'slug' => 'winter-visits']])->assertOk()->json();
        $this->assertArrayNotHasKey('slug', $typed['values']);
    }

    public function test_a_persons_description_and_a_published_address_are_never_written(): void
    {
        $session = $this->editSession('Our own words about winter visits, written by the editor for this page and nobody else, long enough to fit the range.');

        $applied = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['blueprint' => 'service', 'values' => []])->assertOk()->json();

        $this->assertArrayNotHasKey('meta_description', $applied['values'], 'The form keeps the person\'s description.');
        $this->assertArrayNotHasKey('slug', $applied['values'], 'Published pages keep their address.');
        $this->assertTrue(SeoState::of($this->sessions()->find($session->id))->written->isEmpty());

        $search = app(Presenter::class)->detail($this->sessions()->find($session->id))['search'];
        $this->assertSame('suggest', $search['description']['action']);
        $this->assertSame('Your SEO description stays. Suggested instead:', $search['description']['note_text']);
        $this->assertFalse($search['address']['editable']);
        $this->assertSame('Published pages keep their address.', $search['address']['note_text']);
        $this->patchJson(cp_route('ghostwriter.sessions.search.update', $session->id), ['role' => 'slug', 'text' => 'somewhere-else'])->assertStatus(422);

        // "Use this": the draft's text goes in after all.
        $this->postJson(cp_route('ghostwriter.sessions.search.use', $session->id), ['role' => 'description'])->assertOk();
        $applied = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['blueprint' => 'service', 'values' => []])->assertOk()->json();
        $this->assertSame(self::DESCRIPTION, $applied['values']['meta_description']);
        $this->ai->assertNothingSent();
    }

    public function test_finish_this_page_offers_the_drafts_description_where_the_entrys_own_is_too_short(): void
    {
        $session = $this->editSession('Winter visits.');

        $applied = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['blueprint' => 'service', 'values' => []])->assertOk()->json();
        $this->assertArrayNotHasKey('meta_description', $applied['values'], 'A person\'s text: suggested, not written.');

        $gap = collect($applied['gaps']['gaps'])->firstWhere('kind', 'seo-missing');
        $this->assertNotNull($gap);
        $this->assertSame('suggestion', $gap['severity']);
        $this->assertSame('Add a description for search', $gap['step']);
        $this->assertSame(['use-text', self::DESCRIPTION, true], [$gap['fixes'][0]['action'], $gap['fixes'][0]['value'], $gap['fixes'][0]['primary']]);
        $this->assertSame('focus', $gap['fixes'][1]['action']);
        $this->assertSame(0, $applied['gaps']['blocking'] ?? 0);

        // The live check finds the same session's description.
        $report = app(EntryGaps::class)->forForm(Entry::find('winter')->blueprint(), ['meta_description' => 'Winter visits.'], Entry::find('winter'), 'services', $this->sessions()->find($session->id));
        $this->assertContains('seo-missing', array_map(fn ($gap) => $gap->kind->value, $report->all()));
    }

    public function test_edits_in_the_search_section_are_the_editors_and_call_nothing(): void
    {
        $session = $this->startedSession();
        $this->firstDraft($session);
        $this->ai->reset();

        $given = $this->patchJson(cp_route('ghostwriter.sessions.search.update', $session->id), ['role' => 'title', 'text' => 'Winter garden care visits'])->assertOk()->json('search.title');
        $this->assertTrue($given['own']);
        $this->assertSame('Winter garden care visits', $given['text']);
        $this->assertSame('Yours now: a later turn won\'t rewrite it.', $given['note_text']);

        $this->patchJson(cp_route('ghostwriter.sessions.search.update', $session->id), ['role' => 'description', 'text' => 'Short.'])->assertOk()->assertJsonPath('search.description.out', true);
        $this->patchJson(cp_route('ghostwriter.sessions.search.update', $session->id), ['role' => 'slug', 'text' => 'Winter Visits!'])->assertOk()->assertJsonPath('search.address.slug', 'winter-visits');

        $meta = SeoState::of($this->sessions()->find($session->id))->meta;
        $this->assertSame(['title', 'description', 'slug'], $meta->edited);

        // "Use the page title" takes the title back.
        $this->patchJson(cp_route('ghostwriter.sessions.search.update', $session->id), ['role' => 'title', 'text' => ''])->assertOk()->assertJsonPath('search.title.own', false);
        $this->ai->assertNothingSent();

        // An edited description isn't recorded as Ghostwriter's.
        $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id), ['blueprint' => 'service', 'values' => ['title' => '', 'slug' => '']])->assertOk()->assertJsonPath('values.meta_description', 'Short.')->assertJsonPath('values.slug', 'winter-visits');
        $this->assertTrue(SeoState::of($this->sessions()->find($session->id))->written->isEmpty());
    }

    public function test_try_again_is_one_seo_call_and_a_failure_is_said(): void
    {
        $session = $this->startedSession();
        $this->firstDraft($session);
        $this->ai->reset();

        $this->ai->respondStructured('seo-editor', ['notes' => '…', 'links' => [], 'markers' => [], 'title' => '', 'description' => self::ANOTHER]);
        $this->postJson(cp_route('ghostwriter.sessions.search.try_again', $session->id))->assertOk();

        $this->assertSame(['seo-editor'], array_map(fn (TextRequest $request) => $request->agent, $this->ai->requests()));
        $this->assertStringContainsString(self::DESCRIPTION, $this->ai->prompted('seo-editor')[0]->prompt, 'Written differently from the one there now.');
        $this->assertSame(self::ANOTHER, SeoState::of($this->sessions()->find($session->id))->meta->description);
        $this->assertFalse(DraftLayouts::isWriting($session->id));

        $this->ai->reset();
        $this->ai->failWith('seo-editor', new ProviderException('The provider is overloaded.', 'anthropic'));
        $this->postJson(cp_route('ghostwriter.sessions.search.try_again', $session->id))->assertOk();

        $detail = app(Presenter::class)->detail($this->sessions()->find($session->id));
        $this->assertSame('That didn\'t work. Try again in a moment.', $detail['search']['failed']);
        $this->assertSame(self::ANOTHER, $detail['search']['description']['text'], 'Nothing changed.');
    }

    public function test_a_draft_edited_while_the_seo_call_ran_keeps_the_search_meta_and_what_was_written(): void
    {
        $session = $this->startedSession();

        $this->ai->respond('writer', "<reply>Here it is.</reply>\n<draft>\n".self::DRAFT."\n</draft>");
        $this->ai->respond('seo-editor', function () use ($session) {
            // Meanwhile, the draft is edited by hand, and an earlier apply recorded its text.
            app(SessionGuard::class)->change($session->id, function (Session $latest) {
                $latest->draft = str_replace('a little care now', 'a little care in November', (string) $latest->draft);
                SeoState::of($latest)->withWritten((new SeoProvenance)->with(SeoField::DESCRIPTION, 'Before.'))->saveTo($latest);
            });

            return (string) json_encode(['notes' => '…', 'links' => [], 'markers' => [], 'title' => '', 'description' => self::DESCRIPTION]);
        });
        $this->ai->respondStructured('layout-planner', ['plans' => []]);
        $this->runJob(new RunSessionTurn($session->id));

        $state = SeoState::of($this->sessions()->find($session->id));
        $this->assertStringContainsString('a little care in November', (string) $this->sessions()->find($session->id)->draft);
        $this->assertSame(self::DESCRIPTION, $state->meta->description);
        $this->assertTrue($state->written->owns(SeoField::DESCRIPTION, 'Before.'));
    }

    private function firstDraft(Session $session): void
    {
        $this->ai->respond('writer', "<reply>Here it is.</reply>\n<draft>\n".self::DRAFT."\n</draft>");
        $this->ai->respondStructured('seo-editor', ['notes' => 'Winter care.', 'links' => [], 'markers' => [], 'title' => '', 'description' => self::DESCRIPTION]);
        $this->ai->respondStructured('layout-planner', ['plans' => []]);

        $this->runJob(new RunSessionTurn($session->id));
    }

    private function startedSession(): Session
    {
        $user = $this->signInWith(['access ghostwriter', 'view services entries', 'edit services entries', 'create services entries']);
        $type = app(TypeRepository::class)->find('services');
        $session = $this->makeSession('services', ['what' => 'Winter care for established gardens.'], $user->id());
        $session->addMessage('user', app(Studio::class)->brief($type, $session));
        $session->status = Session::WORKING;

        return $this->sessions()->save($session);
    }

    /** A published entry being edited, with the draft and its description already written. */
    private function editSession(string $description): Session
    {
        $this->signInWith(['access ghostwriter', 'view services entries', 'edit services entries', 'create services entries']);
        Entry::make()->collection('services')->id('winter')->slug('winter-care')->published(true)->data(['title' => 'Winter care', 'meta_description' => $description])->save();

        $session = $this->makeSession('services');
        $session->addMessage('user', 'A brief.');
        $session->answer('Here it is.', self::DRAFT);
        $session->source = 'winter';
        $session->editing = true;
        (new SeoState(meta: new SearchMeta(description: self::DESCRIPTION, slug: 'winter-garden-care')))->saveTo($session);

        return $this->sessions()->save($session);
    }
}
