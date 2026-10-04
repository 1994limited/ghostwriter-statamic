<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SeoFieldsContract;
use NineteenNinetyFour\Ghostwriter\Suggest\StatamicSeoFields;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's SeoFieldsContract against StatamicSeoFields, for SEO Pro's field
 * as it stores its values: a custom string, `@seo:summary`, nothing (the
 * section's defaults, an Antlers template), or false. Plain fields too.
 */
final class SeoFieldsTest extends TestCase
{
    use SeoFieldsContract;

    protected function seoFields(): SeoFields
    {
        return app(SeoFields::class);
    }

    protected function seoEntry(string $state): array
    {
        $schema = Schema::fromSpecs([
            ['handle' => 'title', 'type' => 'text', 'kind' => 'text', 'display' => 'Title'],
            ['handle' => 'summary', 'type' => 'textarea', 'kind' => 'longtext', 'display' => 'Summary'],
            ['handle' => 'seo', 'type' => 'seo_pro', 'kind' => 'reference', 'display' => 'SEO'],
        ]);

        $description = match ($state) {
            'custom' => 'From a single planting plan to a full design and build, across Northumberland, Durham and the Tyne Valley.',
            'field' => '@seo:summary',
            'template' => null,
            default => false,
        };

        return [$schema, new EntryData([
            'title' => 'Services',
            'summary' => 'Garden design and planting plans.',
            'seo' => array_filter(['title' => 'Services | Northfold', 'description' => $description], fn ($value) => $value !== null),
        ])];
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(StatamicSeoFields::class, $this->seoFields());
    }

    public function test_seo_pro_values_are_found_by_their_place_in_the_field(): void
    {
        [$schema, $entry] = $this->seoEntry('field');
        $found = $this->seoFields()->in($schema, $entry);
        $description = array_values(array_filter($found, fn (SeoField $field) => $field->role === SeoField::DESCRIPTION))[0];

        $this->assertSame('seo.description', $description->path->dotted());
        $this->assertSame('Summary', $description->inheritsFrom);
        $this->assertSame('Garden design and planting plans.', $description->text);
    }

    public function test_plain_fields_keep_their_character_limit(): void
    {
        $schema = Schema::fromSpecs([['handle' => 'meta_description', 'type' => 'textarea', 'kind' => 'longtext', 'display' => 'Meta description', 'character_limit' => 150]]);
        $found = $this->seoFields()->in($schema, new EntryData(['meta_description' => str_repeat('word ', 40)]));

        $this->assertCount(1, $found);
        $this->assertSame(150, $found[0]->limit);
        $this->assertTrue($found[0]->tooLong());
    }
}
