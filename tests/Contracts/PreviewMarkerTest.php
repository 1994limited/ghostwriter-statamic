<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PreviewMarkerContract;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

/**
 * Core's PreviewMarkerContract through the addon's real paths: a draft's
 * markdown built into entry data as apply builds it (EntryLayouts, with
 * MarkdownToBard for Bard), then printed as Antlers prints the field:
 * Bard and markdown through their fieldtypes' augmentation, plain text as
 * it is.
 */
final class PreviewMarkerTest extends TestCase
{
    use PreviewMarkerContract;

    private const FIELDS = ['rich' => 'body', 'markdown' => 'notes', 'plain' => 'intro'];

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('visits')->title('Visits')->save();
        Blueprint::make('visit')->setNamespace('collections.visits')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'intro', 'field' => ['type' => 'text']],
            ['handle' => 'notes', 'field' => ['type' => 'markdown']],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'buttons' => ['h2', 'bold', 'italic', 'unorderedlist', 'link']]],
        ]])->save();
    }

    protected function storedValue(string $markdown, string $shape): mixed
    {
        $blueprint = Collection::findByHandle('visits')->entryBlueprint();
        $schema = app(SchemaReader::class)->schema($blueprint);
        $handle = self::FIELDS[$shape];

        return app(EntryLayouts::class)->build(['title' => 'A visit', $handle => $markdown], $schema)->data[$handle] ?? null;
    }

    protected function renderValue(mixed $stored, string $shape): string
    {
        $field = Collection::findByHandle('visits')->entryBlueprint()->field(self::FIELDS[$shape]);

        return (string) $field->setValue($stored)->augment()->value();
    }

    protected function previewField(string $shape): Field
    {
        return match ($shape) {
            'rich' => new Field('copy', Kind::RichText, 'Copy', type: 'bard'),
            'markdown' => new Field('copy', Kind::LongText, 'Copy', type: 'markdown'),
            'plain' => new Field('copy', Kind::Text, 'Copy', type: 'text'),
        };
    }
}
