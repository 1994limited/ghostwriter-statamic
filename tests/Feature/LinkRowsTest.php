<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ReasonKind;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkIndex;
use NineteenNinetyFour\Ghostwriter\Storage\FileRevisitStore;
use NineteenNinetyFour\Ghostwriter\Suggest\FileEntryIndex;
use NineteenNinetyFour\Ghostwriter\Suggest\Revisit;
use NineteenNinetyFour\Ghostwriter\Tests\Concerns\LinkSites;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * The link index's link rows on Statamic (SEO layer §7.1): written by the
 * daily pass for every routed collection Ghostwriter doesn't write for,
 * key pages from navigation trees, terms with text of their own, groups
 * joining or leaving Ghostwriter's, and a deleted page's linkers checked
 * again. No model.
 */
final class LinkRowsTest extends TestCase
{
    use LinkSites;

    private string $views;

    protected function setUp(): void
    {
        parent::setUp();

        $this->linkSites();
        Collection::make('site_snippets')->title('Snippets')->sites(['default'])->save();
    }

    protected function tearDown(): void
    {
        $this->tearDownLinkSites();
        Blueprint::find('collections.linker_pages.linker_page')?->delete();

        if (isset($this->views)) {
            File::deleteDirectory($this->views);
        }

        parent::tearDown();
    }

    public function test_the_daily_pass_writes_link_rows_for_other_routed_collections_only(): void
    {
        config(['ghostwriter.revisit.on_save' => false]);

        $this->saveEntry('about', 'site_info', 'About the studio', ['body' => 'We have designed gardens across the north for twenty years.']);
        $this->saveEntry('contact', 'site_info', 'Contact us');
        $this->saveEntry('draft', 'site_info', 'A draft page', published: false);
        $this->saveEntry('snippet', 'site_snippets', 'A snippet with no route');
        $this->saveEntry('design', 'site_pages', 'Garden design', ['body' => 'A garden design from our studio.']);
        Nav::make('main')->title('Main')->collections(['site_info'])->save();
        Nav::find('main')->makeTree('default', [['entry' => 'contact']])->save();

        app(Revisit::class)->daily(full: true, site: 'default');
        $meta = app(FileEntryIndex::class)->meta('default');

        $this->assertSame('link', $meta['site_info:about@default']['scope']);
        $this->assertSame('link', $meta['site_info:contact@default']['scope']);
        $this->assertSame('full', $meta['site_pages:design@default']['scope']);
        $this->assertArrayNotHasKey('site_info:draft@default', $meta);
        $this->assertArrayNotHasKey('site_snippets:snippet@default', $meta);
        $this->assertTrue(app(FileEntryIndex::class)->row(new EntryRef('site_info', 'contact', 'default'))->key, 'In a navigation tree.');
        $this->assertSame('Information', app(FileEntryIndex::class)->row(new EntryRef('site_info', 'about', 'default'))->type);
        $this->assertSame('/about', app(FileEntryIndex::class)->row(new EntryRef('site_info', 'about', 'default'))->url);
        $this->assertSame([], $this->ai->requests());
    }

    public function test_a_collection_joining_ghostwriters_gets_full_rows_and_one_leaving_gets_link_rows(): void
    {
        $this->saveEntry('about', 'site_info', 'About the studio', ['body' => 'We have designed gardens across the north for twenty years, from courtyards to walled gardens.']);
        $this->saveEntry('design', 'site_pages', 'Garden design', ['body' => 'A garden design from our studio, with a survey, a concept and a planting plan.']);
        $index = app(FileEntryIndex::class);

        $this->assertSame('link', $index->meta('default')['site_info:about@default']['scope']);

        config(['ghostwriter.collections' => ['site_info']]);
        app(Revisit::class)->daily(full: true, site: 'default');
        $meta = $index->fresh()->meta('default');

        $this->assertSame('full', $meta['site_info:about@default']['scope']);
        $this->assertSame('link', $meta['site_pages:design@default']['scope']);
    }

    public function test_deleting_a_link_only_page_flags_the_pages_that_linked_to_it(): void
    {
        config(['ghostwriter.collections' => ['linker_pages']]);
        Collection::make('linker_pages')->title('Services')->routes('/services/{slug}')->sites(['default', 'cy'])->save();
        Blueprint::make('linker_page')->setNamespace('collections.linker_pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'display' => 'Body', 'buttons' => ['bold', 'link']]],
        ]])->save();

        $this->saveEntry('contact', 'site_info', 'Contact us');
        $this->saveEntry('winter', 'linker_pages', 'Winter care', ['body' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Ask about winter visits: '],
            ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::contact']]], 'text' => 'get in touch'],
            ['type' => 'text', 'text' => '.'],
        ]]]]);
        $linker = new EntryRef('linker_pages', 'winter', 'default');

        $this->assertFalse(app(RevisitStore::class)->get($linker)->has(ReasonKind::BrokenLink));

        Entry::find('contact')->delete();

        $this->assertTrue(app(RevisitStore::class)->get($linker)->has(ReasonKind::BrokenLink));
        $this->assertNull(app(FileEntryIndex::class)->row(new EntryRef('site_info', 'contact', 'default')));
    }

    public function test_terms_with_text_of_their_own_are_link_targets_and_bare_ones_are_not(): void
    {
        $this->views = sys_get_temp_dir().'/gw-link-views-'.uniqid();
        File::ensureDirectoryExists($this->views.'/topics');
        File::put($this->views.'/topics/show.antlers.html', '{{ title }}');
        View::addLocation($this->views);

        Taxonomy::make('topics')->title('Topics')->sites(['default'])->save();
        Term::make('pruning')->taxonomy('topics')->dataForLocale('default', ['title' => 'Pruning', 'content' => str_repeat('Pruning keeps fruit trees healthy and productive through the winter. ', 6)])->save();
        Term::make('roses')->taxonomy('topics')->dataForLocale('default', ['title' => 'Roses', 'content' => 'Only a few words.'])->save();

        $found = array_map(fn (DigestEntry $e) => $e->entry?->key(), app(LinkIndex::class)->related("Pruning fruit trees\n\nWinter pruning for apple trees and roses.", 'site_pages', 'default'));

        $this->assertContains('taxonomy:topics:topics::pruning@default', $found);
        $this->assertNotContains('taxonomy:topics:topics::roses@default', $found);
    }

    public function test_a_navigation_change_marks_every_group_for_the_next_pass(): void
    {
        Nav::make('footer')->title('Footer')->collections(['site_info'])->save();
        Nav::find('footer')->makeTree('default', [])->save();

        $state = json_decode((string) File::get(FileRevisitStore::path().'/links.json'), true);

        $this->assertArrayHasKey('*', $state['marks']);
    }
}
