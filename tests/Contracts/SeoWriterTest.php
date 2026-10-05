<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\MetaAction;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SearchFields;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SearchMeta;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoWriter;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SeoWriterContract;
use NineteenNinetyFour\Ghostwriter\Seo\StatamicSeoWriter;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Collection;

/**
 * Core's SeoWriterContract against StatamicSeoWriter, through
 * StatamicSeoFields, for SEO Pro 7.15 in the shapes it really stores
 * (captured on the Northfold test site, 2026-10-04, as SeoFieldsTest): a
 * description of the entry's own is a string, `@seo:excerpt` takes the
 * excerpt, Antlers is a template, `false` is switched off. The site
 * defaults here set no description, so an entry with none has none.
 * Then the publish form's `{source, value}` shape, and plain fields.
 *
 * (The contract is used as a trait on the addon's TestCase, as
 * SeoFieldsTest uses SeoFieldsContract: StatamicSeoFields reads the
 * collection's and site's defaults through Statamic.)
 */
final class SeoWriterTest extends TestCase
{
    use SeoWriterContract;

    /** resources/addons/seo-pro.yaml: the site name, and no description default. */
    private const SITE_DEFAULTS = <<<'YAML'
        site_defaults:
          site_name: 'Northfold Gardens'
          site_name_position: after
          site_name_separator: '|'
          title: '@seo:title'
        YAML;

    private const EXCERPT = 'A small back yard, a downpipe that flooded the kitchen step every winter, and a planted hollow that now takes all of it.';

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(resource_path('addons'));
        File::put(resource_path('addons/seo-pro.yaml'), self::SITE_DEFAULTS);
        Collection::make('journal')->title('Journal')->dated(true)->save();
    }

    protected function tearDown(): void
    {
        File::delete(resource_path('addons/seo-pro.yaml'));

        parent::tearDown();
    }

    protected function seoWriterFields(): SeoFields
    {
        return app(SeoFields::class);
    }

    protected function seoWriter(): SeoWriter
    {
        return app(SeoWriter::class);
    }

    private function schema(): Schema
    {
        // The Journal blueprint, as the schema reader gives it, with SEO Pro's injected field.
        return Schema::fromSpecs([
            ['handle' => 'title', 'type' => 'text', 'kind' => 'text', 'display' => 'Title'],
            ['handle' => 'excerpt', 'type' => 'textarea', 'kind' => 'longtext', 'display' => 'Excerpt'],
            ['handle' => 'seo', 'type' => 'seo_pro', 'kind' => 'reference', 'display' => 'SEO'],
        ]);
    }

    /**
     * @param  array<string, mixed>|false|null  $seo
     */
    private function journalPost(array|false|null $seo): EntryData
    {
        $values = ['title' => 'A rain garden for a terrace in Headingley', 'excerpt' => self::EXCERPT];

        if ($seo !== null) {
            $values['seo'] = $seo;
        }

        return new EntryData($values, '3eef1772-6216-48ba-bd10-d1c87ec32f53', group: 'journal', site: 'default');
    }

    protected function seoWriterEntry(string $state): ?array
    {
        $entry = match ($state) {
            'empty' => $this->journalPost(null),
            'custom' => $this->journalPost(['title' => 'A rain garden in Headingley', 'description' => 'How a planted hollow took the water from a Headingley terrace\'s downpipe, and gave the owners a garden.', 'robots_indexing' => 'index']),
            'field' => $this->journalPost(['description' => '@seo:excerpt']),
            'template' => $this->journalPost(['description' => '{{ excerpt | truncate:150 }}']),
            'disabled' => $this->journalPost(['description' => false]),
        };

        return [$this->schema(), $entry];
    }

    private function field(EntryData $entry, string $role = SeoField::DESCRIPTION, ?Schema $schema = null): SeoField
    {
        return array_values(array_filter(app(SeoFields::class)->in($schema ?? $this->schema(), $entry), fn (SeoField $field) => $field->role === $role))[0];
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(StatamicSeoWriter::class, $this->seoWriter());
    }

    public function test_seo_pros_other_keys_are_kept(): void
    {
        $entry = $this->journalPost(['title' => 'A rain garden in Headingley', 'robots_indexing' => 'noindex']);
        $values = $this->seoWriter()->write($entry->values, $this->field($entry), $this->seoWriterText());

        $this->assertSame(['title' => 'A rain garden in Headingley', 'robots_indexing' => 'noindex', 'description' => $this->seoWriterText()], $values['seo']);
        $this->assertSame(self::EXCERPT, $values['excerpt']);
    }

    public function test_the_publish_forms_shape_is_written_as_seo_pro_holds_it(): void
    {
        $form = ['enabled' => true, 'title' => ['source' => 'inherit', 'value' => null], 'description' => ['source' => 'field', 'value' => 'excerpt']];
        $entry = $this->journalPost($form);

        $values = $this->seoWriter()->write($entry->values, $this->field($entry), $this->seoWriterText());

        $this->assertSame(['source' => 'custom', 'value' => $this->seoWriterText()], $values['seo']['description']);
        $this->assertSame($form['title'], $values['seo']['title']);
        $this->assertTrue($values['seo']['enabled']);

        $read = $this->field($entry->withValues($values));
        $this->assertSame(SeoSource::Custom, $read->source);
        $this->assertSame($this->seoWriterText(), $read->text);

        // Asked for the form's shape outright (Finish this page, Suggest edits).
        $stored = $this->journalPost(null);
        $this->assertSame(['source' => 'custom', 'value' => 'Winter care'], (new StatamicSeoWriter(form: true))->write($stored->values, $this->field($stored, SeoField::TITLE), 'Winter care')['seo']['title']);
    }

    public function test_plain_fields_take_the_text_as_it_is(): void
    {
        $schema = Schema::fromSpecs([
            ['handle' => 'title', 'type' => 'text', 'kind' => 'text', 'display' => 'Title'],
            ['handle' => 'meta_description', 'type' => 'textarea', 'kind' => 'longtext', 'display' => 'Meta description', 'character_limit' => 160],
        ]);
        $entry = new EntryData(['title' => 'Winter care', 'meta_description' => '']);

        $values = $this->seoWriter()->write($entry->values, $this->field($entry, schema: $schema), $this->seoWriterText());

        $this->assertSame($this->seoWriterText(), $values['meta_description']);
        $this->assertSame($this->seoWriterText(), $this->field($entry->withValues($values), schema: $schema)->text);
    }

    public function test_a_title_that_inherits_the_page_title_can_be_given_its_own(): void
    {
        // SEO Pro's default, `@seo:title`: the page title fits, so Ghostwriter leaves it (decision 12).
        $entry = $this->journalPost(null);
        $entry = $entry->withValues(['title' => 'Rain gardens'] + $entry->values);
        $field = $this->field($entry, SeoField::TITLE);
        $this->assertTrue($field->inherited());

        $apply = fn (SearchMeta $meta) => (new SearchFields($this->seoWriterFields(), $this->seoWriter()))->apply($entry->values, $this->schema(), $entry, new SeoState(meta: $meta), true);

        $left = $apply(new SearchMeta(title: 'Rain garden for a Headingley terrace'));
        $this->assertArrayNotHasKey('seo', $left->values);
        $this->assertSame(MetaAction::Leave, $left->actions[SeoField::TITLE]);

        // "Give it its own" in the Search section: the editor's title is written.
        $given = $apply((new SearchMeta)->with(SeoField::TITLE, 'Rain garden for a Headingley terrace', edited: true));
        $this->assertSame(['title' => 'Rain garden for a Headingley terrace'], $given->values['seo']);
        $this->assertSame(SeoSource::Custom, $this->field($entry->withValues($given->values), SeoField::TITLE)->source);
    }
}
