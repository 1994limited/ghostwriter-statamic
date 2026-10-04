<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SeoFieldsContract;
use NineteenNinetyFour\Ghostwriter\Suggest\StatamicSeoFields;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Collection;

/**
 * Core's SeoFieldsContract against StatamicSeoFields, for SEO Pro 7.15 as
 * the Northfold test site has it set up (captured 2026-10-04): the site
 * defaults in `resources/addons/seo-pro.yaml` ("Northfold Gardens", `|`,
 * after; the description from `excerpt`), the Journal's `inject: seo:
 * description: '@seo:excerpt'`, the Pages' `title: '@seo:title'`, and a
 * Journal post with an excerpt and a Bard body. SEO Pro itself isn't
 * installed here: the file and the collections are what it reads.
 */
final class SeoFieldsTest extends TestCase
{
    use SeoFieldsContract;

    /** resources/addons/seo-pro.yaml on the test site. */
    private const SITE_DEFAULTS = <<<'YAML'
        site_defaults:
          site_name: 'Northfold Gardens'
          site_name_position: after
          site_name_separator: '|'
          title: '@seo:title'
          description: '@seo:excerpt'
          canonical_url: '@seo:permalink'
          og_type: website
          priority: 0.5
          change_frequency: monthly
        YAML;

    private const EXCERPT = 'A small back yard, a downpipe that flooded the kitchen step every winter, and a planted hollow that now takes all of it.';

    /** The start of the post's Bard body, as stored. */
    private const BODY = [
        ['type' => 'paragraph', 'attrs' => ['textAlign' => 'left'], 'content' => [['type' => 'text', 'text' => 'This one started with a puddle. Not a small one.']]],
        ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'left'], 'content' => [['type' => 'text', 'text' => 'How a rain garden works']]],
        ['type' => 'paragraph', 'attrs' => ['textAlign' => 'left'], 'content' => [['type' => 'text', 'text' => 'Water from the roof goes into a '], ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'planted hollow'], ['type' => 'text', 'text' => '.']]],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(resource_path('addons'));
        File::put(resource_path('addons/seo-pro.yaml'), self::SITE_DEFAULTS);
        Collection::make('journal')->title('Journal')->dated(true)->cascade(['seo' => ['description' => '@seo:excerpt']])->save();
        Collection::make('pages')->title('Pages')->cascade(['seo' => ['title' => '@seo:title']])->save();
    }

    protected function tearDown(): void
    {
        File::delete(resource_path('addons/seo-pro.yaml'));

        parent::tearDown();
    }

    protected function seoFields(): SeoFields
    {
        return app(SeoFields::class);
    }

    private function schema(): Schema
    {
        // The Journal blueprint, as the schema reader gives it, with SEO Pro's injected field.
        return Schema::fromSpecs([
            ['handle' => 'title', 'type' => 'text', 'kind' => 'text', 'display' => 'Title'],
            ['handle' => 'excerpt', 'type' => 'textarea', 'kind' => 'longtext', 'display' => 'Excerpt'],
            ['handle' => 'body', 'type' => 'bard', 'kind' => 'richtext', 'display' => 'Body'],
            ['handle' => 'seo', 'type' => 'seo_pro', 'kind' => 'reference', 'display' => 'SEO'],
        ]);
    }

    /**
     * @param  array<string, mixed>|false|null  $seo
     */
    private function journalPost(array|false|null $seo, string $group = 'journal', string $excerpt = self::EXCERPT): EntryData
    {
        $values = ['title' => 'A rain garden for a terrace in Headingley', 'excerpt' => $excerpt, 'body' => self::BODY];

        if ($seo !== null) {
            $values['seo'] = $seo;
        }

        return new EntryData($values, '3eef1772-6216-48ba-bd10-d1c87ec32f53', group: $group, site: 'default');
    }

    protected function seoEntry(string $state): ?array
    {
        $entry = match ($state) {
            'custom' => $this->journalPost(['title' => 'A rain garden in Headingley', 'description' => 'How a planted hollow took the water from a Headingley terrace\'s downpipe, and gave the owners a garden.']),
            'field' => $this->journalPost(['description' => '@seo:excerpt'], 'pages'),
            'template' => $this->journalPost(['description' => '{{ excerpt | truncate:150 }}']),
            'disabled' => $this->journalPost(['description' => false]),
            'section' => $this->journalPost(null),
            'noindex' => $this->journalPost(['robots_indexing' => 'noindex']),
        };

        return [$this->schema(), $entry];
    }

    protected function seoTitle(): ?array
    {
        return ['A rain garden for a terrace in Headingley', 'A rain garden for a terrace in Headingley | Northfold Gardens'];
    }

    private function find(EntryData $entry, string $role = SeoField::DESCRIPTION): SeoField
    {
        return array_values(array_filter($this->seoFields()->in($this->schema(), $entry), fn (SeoField $field) => $field->role === $role))[0];
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(StatamicSeoFields::class, $this->seoFields());
    }

    public function test_seo_pro_values_are_found_by_their_place_in_the_field(): void
    {
        $description = $this->find($this->journalPost(['description' => '@seo:excerpt']));

        $this->assertSame('seo.description', $description->path->dotted());
        $this->assertSame('Excerpt', $description->inheritsFrom);
        $this->assertSame(self::EXCERPT, $description->text);
        $this->assertTrue($description->writable, 'Accepting gives the page its own: SEO Pro\'s custom source.');
    }

    public function test_null_takes_the_journals_default_and_the_title_the_sites(): void
    {
        $post = $this->journalPost(null);
        $description = $this->find($post);
        $title = $this->find($post, SeoField::TITLE);

        $this->assertSame(SeoSource::Field, $description->source, 'Not "can\'t evaluate": the Journal\'s default is a plain @seo:excerpt.');
        $this->assertSame(self::EXCERPT, $description->text);
        $this->assertSame(SeoSource::Field, $title->source);
        $this->assertSame('Title', $title->inheritsFrom);
        $this->assertSame('A rain garden for a terrace in Headingley', $title->text);
    }

    public function test_a_bard_source_is_read_as_its_plain_text(): void
    {
        $description = $this->find($this->journalPost(['description' => '@seo:body']));

        $this->assertSame('Body', $description->inheritsFrom);
        // As SEO Pro's bardText prints it: a space after a paragraph only, so none before a heading.
        $this->assertSame('This one started with a puddle. Not a small one.How a rain garden works Water from the roof goes into a planted hollow.', $description->text);
    }

    public function test_an_empty_source_falls_back_to_the_section_then_the_site(): void
    {
        // The entry's own @seo:summary is empty: the Journal's @seo:excerpt applies.
        $journal = $this->find($this->journalPost(['description' => '@seo:summary']));
        $this->assertSame(self::EXCERPT, $journal->text);
        $this->assertSame('Excerpt', $journal->inheritsFrom);

        // A page with no excerpt: the section has no description, the site's own @seo:excerpt is empty too.
        $page = $this->find($this->journalPost(null, 'pages', ''));
        $this->assertSame(SeoSource::Field, $page->source);
        $this->assertTrue($page->isEmpty(), 'Inherited, and empty: worth a description of its own.');

        // The site default's fixed text, the same on every page.
        File::put(resource_path('addons/seo-pro.yaml'), str_replace("'@seo:excerpt'", "'Garden design and care across the North East.'", self::SITE_DEFAULTS));
        $default = $this->find($this->journalPost(null, 'pages', ''));
        $this->assertSame(SeoSource::Default, $default->source);
        $this->assertSame('Garden design and care across the North East.', $default->text);
    }

    public function test_the_collection_form_of_a_source_is_read_from_the_entry(): void
    {
        $description = $this->find($this->journalPost(['description' => '@seo:journal/excerpt']));

        $this->assertSame(self::EXCERPT, $description->text);
        $this->assertSame('Excerpt', $description->inheritsFrom);
    }

    public function test_the_whole_field_switched_off_prints_nothing(): void
    {
        $fields = $this->seoFields()->in($this->schema(), $this->journalPost(false));

        $this->assertSame([SeoSource::Disabled, SeoSource::Disabled], array_map(fn (SeoField $field) => $field->source, $fields));
        $this->assertFalse($this->seoFields()->noindex($this->schema(), $this->journalPost(false)), 'No robots tag at all: not a noindex.');
        $this->assertNull($this->seoFields()->titleFormat($this->schema(), $this->journalPost(false)));
    }

    public function test_the_publish_forms_shape_reads_the_same(): void
    {
        $form = ['enabled' => true, 'title' => ['source' => 'inherit', 'value' => null], 'description' => ['source' => 'field', 'value' => 'body']];

        $this->assertSame('Body', $this->find($this->journalPost($form))->inheritsFrom);
        $this->assertSame(SeoSource::Field, $this->find($this->journalPost($form), SeoField::TITLE)->source);
        $this->assertSame(SeoSource::Disabled, $this->find($this->journalPost(['enabled' => false]))->source);
    }

    public function test_legacy_robots_and_the_cascade(): void
    {
        $schema = $this->schema();

        $this->assertTrue($this->seoFields()->noindex($schema, $this->journalPost(['robots' => ['noindex', 'nofollow']])), 'SEO Pro 6\'s list, still read.');
        $this->assertFalse($this->seoFields()->noindex($schema, $this->journalPost(['robots' => ['noindex'], 'robots_indexing' => 'index'])), 'The new key wins.');

        Collection::make('journal')->cascade(['seo' => ['description' => '@seo:excerpt', 'robots_indexing' => 'noindex']])->save();
        $this->assertTrue($this->seoFields()->noindex($schema, $this->journalPost(null)), 'From the section.');
        $this->assertFalse($this->seoFields()->noindex($schema, $this->journalPost(['robots_indexing' => 'index'])), 'The entry overrides it.');
    }

    public function test_the_site_name_is_overridden_like_any_other_key(): void
    {
        $format = $this->seoFields()->titleFormat($this->schema(), $this->journalPost(['site_name_position' => 'before', 'site_name_separator' => '—']));

        $this->assertNotNull($format);
        $this->assertSame('Northfold Gardens — A rain garden for a terrace in Headingley', $format->compose('A rain garden for a terrace in Headingley'));
    }

    public function test_plain_fields_keep_their_character_limit(): void
    {
        $schema = Schema::fromSpecs([['handle' => 'meta_description', 'type' => 'textarea', 'kind' => 'longtext', 'display' => 'Meta description', 'character_limit' => 150]]);
        $found = $this->seoFields()->in($schema, new EntryData(['meta_description' => str_repeat('word ', 40)]));

        $this->assertCount(1, $found);
        $this->assertSame(150, $found[0]->limit);
        $this->assertTrue($found[0]->tooLong());
        $this->assertNull($this->seoFields()->titleFormat($schema, new EntryData([])));
    }
}
