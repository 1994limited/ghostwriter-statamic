<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Carbon;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkProbe;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkResult;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkStatus;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ReasonKind;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitRow;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Suggest\Revisit;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Content to revisit kept current with no model: a save scans the entry,
 * a delete checks again the pages that linked to it, the daily command
 * reads what changed, and the weekly check of links to other sites asks
 * nobody anything until a manager turns it on.
 */
class ContentToRevisitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ghostwriter.revisit.on_save' => true]);

        Collection::make('revisit_pages')->title('Pages')->routes('/{slug}')->save();
        Blueprint::make('revisit_page')->setNamespace('collections.revisit_pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'eyebrow', 'field' => ['type' => 'text', 'display' => 'Eyebrow']],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'display' => 'Body', 'buttons' => ['bold', 'link']]],
        ]])->save();

        Entry::make()->id('show-garden')->collection('revisit_pages')->slug('show-garden')->published(true)->data(['title' => 'Our show garden'])->save();
        $this->services();
    }

    protected function tearDown(): void
    {
        Blueprint::find('collections.revisit_pages.revisit_page')?->delete();

        parent::tearDown();
    }

    private function services(array $overrides = []): void
    {
        Entry::make()->id('services')->collection('revisit_pages')->slug('services')->published(true)->data($overrides + [
            'title' => 'Services',
            'eyebrow' => 'New for 2024: winter care visits',
            'updated_at' => Carbon::now()->subMonths(30)->getTimestamp(),
            'body' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'See '],
                    ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::show-garden']]], 'text' => 'our show garden'],
                    ['type' => 'text', 'text' => ', or '],
                    ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.org/gone']]], 'text' => 'the RHS write-up'],
                    ['type' => 'text', 'text' => '.'],
                ]],
            ],
        ])->save();
    }

    private function row(string $id): ?RevisitRow
    {
        return app(RevisitStore::class)->get(new EntryRef('revisit_pages', $id, 'default'));
    }

    public function test_a_save_scans_the_entry_with_no_model(): void
    {
        $row = $this->row('services');

        $this->assertNotNull($row);
        $this->assertTrue($row->has(ReasonKind::PastYear));
        $this->assertGreaterThan(0, $row->score);
        $this->assertNotEmpty(array_filter($row->linksTo, fn (string $target) => str_ends_with($target, 'entry::show-garden')));
        $this->assertSame([], $this->ai->requests());
    }

    public function test_deleting_a_page_flags_the_pages_that_linked_to_it(): void
    {
        $this->assertFalse($this->row('services')->has(ReasonKind::BrokenLink));

        Entry::find('show-garden')->delete();

        $this->assertTrue($this->row('services')->has(ReasonKind::BrokenLink));
        $this->assertNull($this->row('show-garden'));
    }

    public function test_unpublishing_takes_the_entry_off_the_list(): void
    {
        Entry::find('services')->published(false)->save();

        $this->assertNull($this->row('services'));
    }

    public function test_the_daily_command_reads_everything_the_first_time(): void
    {
        app(RevisitStore::class)->forget(new EntryRef('revisit_pages', 'services', 'default'));

        $this->artisan('ghostwriter:revisit')->assertSuccessful();

        $this->assertNotNull($this->row('services'));
        $this->assertSame([], $this->ai->requests());
    }

    public function test_links_to_other_sites_are_never_checked_until_a_manager_turns_it_on(): void
    {
        $probe = new class implements LinkProbe
        {
            public array $asked = [];

            public function probe(string $url, int $timeout): LinkResult
            {
                $this->asked[] = $url;

                return new LinkResult($url, LinkStatus::Broken, 404, gmdate(DATE_ATOM));
            }
        };
        $this->app->instance(LinkProbe::class, $probe);

        $this->artisan('ghostwriter:check-links')->assertSuccessful();
        $this->assertSame([], $probe->asked, 'Off by default: nobody is asked.');

        config(['ghostwriter.revisit.external_links' => true]);
        $this->artisan('ghostwriter:check-links')->assertSuccessful();

        $this->assertSame(['https://example.org/gone'], $probe->asked);
        $this->assertSame(LinkStatus::Broken, $this->row('services')->external['https://example.org/gone']->status);
    }

    public function test_the_list_ranks_pages_with_their_reasons_and_a_review_link(): void
    {
        $this->signInWith(['access ghostwriter', 'view revisit_pages entries', 'edit revisit_pages entries']);
        app(Revisit::class)->daily(full: true);

        $this->get(cp_route('ghostwriter.revisit.show'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('ghostwriter::Revisit')
            ->where('rows.0.title', 'Services')
            ->where('rows.0.reasons.0.severity', 'high')
            ->where('rows.0.review_url', fn ($url) => str_ends_with((string) $url, 'ghostwriter=suggest'))
            ->where('reading', false));

        $this->get(cp_route('ghostwriter.revisit.show', ['show' => 'missing-alt']))->assertOk()->assertInertia(fn ($page) => $page->has('rows', 0));
        $this->get(cp_route('ghostwriter.index'))->assertOk()->assertInertia(fn ($page) => $page->where('revisit.top.0.title', 'Services'));
        $this->assertSame([], $this->ai->requests(), 'No model.');
    }

    public function test_a_snoozed_page_leaves_the_list_and_others_see_only_what_they_may(): void
    {
        $this->signInWith(['access ghostwriter', 'view revisit_pages entries', 'edit revisit_pages entries']);
        app(Revisit::class)->daily(full: true);

        $this->postJson(cp_route('ghostwriter.revisit.snooze'), ['key' => 'revisit_pages:services@default'])->assertOk();
        $this->get(cp_route('ghostwriter.revisit.show'))->assertInertia(fn ($page) => $page->has('rows', 0));

        $this->row('services') && $this->assertNotNull($this->row('services')->snoozedUntil);

        config(['statamic.editions.pro' => true]);
        $this->signInWith(['access ghostwriter']);
        $this->postJson(cp_route('ghostwriter.revisit.snooze'), ['key' => 'revisit_pages:services@default'])->assertForbidden();
    }

    public function test_the_three_settings_have_their_defaults(): void
    {
        $settings = app(Settings::class);

        $this->assertFalse($settings->checksExternalLinks());
        $this->assertTrue($settings->checksClaims());
        $this->assertSame([], $settings->ageInFull());
    }
}
