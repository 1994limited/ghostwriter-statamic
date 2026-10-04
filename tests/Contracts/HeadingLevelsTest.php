<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\HeadingLevelsContract;
use NineteenNinetyFour\Ghostwriter\Drafts\BardDialect;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

/**
 * Core's HeadingLevelsContract on Bard: the reader records the heading
 * buttons, and the fitted markdown goes through the real apply path (core's
 * EntryBuilder with MarkdownToBard, the form's pre-processing and saving)
 * with only the levels the buttons offer.
 */
final class HeadingLevelsTest extends TestCase
{
    use HeadingLevelsContract;

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('visits')->title('Visits')->save();
        Blueprint::make('visit')->setNamespace('collections.visits')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'buttons' => ['h2', 'h3', 'bold', 'italic', 'link']]],
            ['handle' => 'note', 'field' => ['type' => 'bard', 'buttons' => ['bold', 'italic']]],
            ['handle' => 'summary', 'field' => ['type' => 'bard']],
        ]])->save();
    }

    protected function twoLevelField(): Field
    {
        return $this->schema()->field('body');
    }

    protected function noHeadingField(): Field
    {
        return $this->schema()->field('note');
    }

    protected function stored(string $markdown, Field $field): string
    {
        $blueprint = Collection::findByHandle('visits')->entryBlueprint();
        $built = app(EntryLayouts::class)->build(['title' => 'A visit', $field->handle => $markdown], $this->schema())->data;
        $form = $blueprint->fields()->addValues($built)->preProcess()->values()->all();
        $saved = $blueprint->fields()->addValues($form)->process()->values()->all();

        return (string) app(BardDialect::class)->toMarkdown($saved[$field->handle] ?? null, $field);
    }

    public function test_a_bard_field_with_no_buttons_set_has_statamics_default_h2_and_h3(): void
    {
        $this->assertSame([2, 3], HeadingLevels::allowed($this->schema()->field('summary')));
    }

    private function schema(): Schema
    {
        return app(SchemaReader::class)->schema(Collection::findByHandle('visits')->entryBlueprint());
    }
}
