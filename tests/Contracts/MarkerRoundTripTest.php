<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\MarkerRoundTripContract;
use NineteenNinetyFour\Ghostwriter\Drafts\BardDialect;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

/**
 * Core's MarkerRoundTripContract through the addon's real apply path: the
 * draft built into entry data (core's EntryBuilder with MarkdownToBard),
 * then each fieldtype's pre-processing, as the publish form receives it,
 * and its processing, as saving the form stores it. Bard is read back
 * with BardDialect; markdown and plain text as stored.
 */
final class MarkerRoundTripTest extends TestCase
{
    use MarkerRoundTripContract;

    private const FIELDS = ['rich' => 'body', 'markdown' => 'notes', 'plain' => 'intro'];

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('visits')->title('Visits')->save();
        Blueprint::make('visit')->setNamespace('collections.visits')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'intro', 'field' => ['type' => 'textarea']],
            ['handle' => 'notes', 'field' => ['type' => 'markdown']],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'buttons' => ['h2', 'bold', 'italic', 'unorderedlist', 'link']]],
        ]])->save();
    }

    protected function roundTripMarkers(string $markdown, string $shape): string
    {
        $blueprint = Collection::findByHandle('visits')->entryBlueprint();
        $schema = app(SchemaReader::class)->schema($blueprint);
        $handle = self::FIELDS[$shape];

        $built = app(EntryLayouts::class)->build(['title' => 'A visit', $handle => $markdown], $schema)->data;

        // Into the form, then saved from it.
        $form = $blueprint->fields()->addValues($built)->preProcess()->values()->all();
        $saved = $blueprint->fields()->addValues($form)->process()->values()->all();
        $value = $saved[$handle] ?? null;

        if ($shape === 'rich') {
            return (string) app(BardDialect::class)->toMarkdown($value, $schema->field($handle));
        }

        return (string) $value;
    }
}
