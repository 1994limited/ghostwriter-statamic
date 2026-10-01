<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use NineteenNinetyFour\Ghostwriter\Blueprints\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaDescriber;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Drafts\EntryBuilder;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Collection;

/**
 * Reading a blueprint, learning the pattern from existing entries, and
 * building entry data from a draft: the three steps that let one writer
 * serve a page-builder site and a plain-body site alike.
 */
class BlueprintTest extends TestCase
{
    private const PARAGRAPH = 'When on-site search is done right it helps your customers find what they need and nudges them towards a decision. Offer up a complex range badly and those high end sales vanish.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeArticlesCollection();
        $this->makePostsCollection();
    }

    public function test_the_reader_reduces_fields_to_kinds(): void
    {
        $schema = collect($this->schema('articles'))->keyBy('handle');

        $this->assertSame('text', $schema['title']['kind']);
        $this->assertTrue($schema['title']['required']);
        $this->assertSame('longtext', $schema['summary']['kind']);
        $this->assertSame('Shown in lists.', $schema['summary']['instructions']);
        $this->assertSame('reference', $schema['featured_image']['kind']);
        $this->assertSame('reference', $schema['author']['kind']);
        $this->assertSame('blocks', $schema['page_builder']['kind']);
        $this->assertArrayNotHasKey('slug', $schema->all());

        // Sets inside display groups come out as one flat map.
        $sets = $schema['page_builder']['sets'];
        $this->assertSame(['hero', 'long_form', 'cards', 'related', 'gallery'], array_keys($sets));

        $longForm = collect($sets['long_form']['fields'])->keyBy('handle');
        $this->assertSame('richtext', $longForm['content']['kind']);
        $this->assertArrayHasKey('pullquote', $longForm['content']['sets']);
        $this->assertSame('toggle', $longForm['numbered']['kind']);
        $this->assertSame(['narrow' => 'Narrow', 'wide' => 'Wide'], $longForm['width']['options']);
        $this->assertSame('rows', collect($sets['cards']['fields'])->keyBy('handle')['items']['kind']);
    }

    public function test_the_pattern_comes_from_the_entries_already_there(): void
    {
        foreach (['one', 'two', 'three'] as $slug) {
            $this->makeArticle($slug, ucfirst($slug), self::PARAGRAPH.' '.ucfirst($slug).'.');
        }

        $schema = $this->schema('articles');
        $pattern = app(PatternFinder::class)->find('articles', $schema);
        $blocks = $pattern['blocks']['page_builder'];

        $this->assertSame(3, $pattern['entries']);

        // The switched-off gallery is not part of the page.
        $this->assertSame(['hero', 'long_form', 'cards', 'related'], $blocks['sequence']);
        $this->assertArrayNotHasKey('gallery', $blocks['usage']);
        $this->assertSame(1.0, $blocks['usage']['long_form']);

        // The same on every entry, so house defaults rather than writing.
        $this->assertSame(['numbered' => true, 'width' => 'narrow'], $blocks['fixed']['long_form']);
        $this->assertSame(['heading' => 'More articles', 'limit' => 3], $blocks['fixed']['related']);
        $this->assertSame('Broader uses', $blocks['fixed']['cards']['heading']);
        $this->assertSame('user-1', $pattern['fixed']['author']);

        // Examples are in the form drafts are written in: markdown, no IDs,
        // no references, and without the settings that never change.
        $example = $pattern['examples'][0];
        $this->assertArrayNotHasKey('featured_image', $example);
        $this->assertSame(['type' => 'hero'], $example['page_builder'][0]);
        $this->assertStringStartsWith("## The Problem\n\n".self::PARAGRAPH, $example['page_builder'][1]['content']);
        $this->assertStringEndsWith('> Challenge accepted.', $example['page_builder'][1]['content']);
        $this->assertArrayNotHasKey('width', $example['page_builder'][1]);

        // Blocks whose content never changes are copied, not written, so the
        // examples only show where they go.
        $this->assertSame(['hero', 'cards', 'related'], $blocks['boilerplate']);
        $this->assertSame(['type' => 'cards'], $example['page_builder'][2]);
    }

    public function test_a_block_that_never_changes_is_copied_into_the_entry(): void
    {
        foreach (['one', 'two', 'three'] as $slug) {
            $this->makeArticle($slug, ucfirst($slug), self::PARAGRAPH.' '.ucfirst($slug).'.');
        }

        $schema = $this->schema('articles');
        $pattern = app(PatternFinder::class)->find('articles', $schema);

        // Whatever the writer put in such a block, the copy wins.
        $built = app(EntryBuilder::class)->build(['title' => 'New', 'page_builder' => [
            ['type' => 'cards', 'items' => [['text' => 'Something made up']]],
        ]], $schema, $pattern)['data'];
        $cards = $built['page_builder'][0];

        $this->assertSame('Broader uses', $cards['heading']);
        $this->assertSame(['Property searches', 'Recipe selection'], array_column($cards['items'], 'text'));

        // Rows are copies, so they get IDs of their own.
        $this->assertNotContains($cards['items'][0]['id'], ['r1', 'r2']);
    }

    public function test_content_pasted_in_a_few_versions_is_still_copied(): void
    {
        $website = [['id' => 'w1', 'text' => 'Website step']];
        $app = [['id' => 'p1', 'text' => 'App step']];

        foreach (['one' => $website, 'two' => $website, 'three' => $website, 'four' => $app, 'five' => $app] as $slug => $items) {
            $this->makeArticle($slug, ucfirst($slug), self::PARAGRAPH.' '.ucfirst($slug).'.', ['page_builder' => [
                ['id' => 'c-'.$slug, 'type' => 'cards', 'enabled' => true, 'heading' => 'Steps', 'items' => $items],
                ['id' => 'g-'.$slug, 'type' => 'gallery', 'enabled' => true, 'caption' => 'Caption for '.$slug],
            ]]);
        }

        $blocks = app(PatternFinder::class)->find('articles', $this->schema('articles'))['blocks']['page_builder'];

        // Neither wording is on 80% of entries, but none is an entry's own.
        $this->assertSame(['cards'], $blocks['boilerplate']);
        $this->assertSame('Website step', $blocks['fixed']['cards']['items'][0]['text']);
        $this->assertArrayNotHasKey('gallery', $blocks['fixed']);
    }

    public function test_a_type_can_learn_from_only_some_of_the_entries(): void
    {
        $this->makeArticle('one', 'One', self::PARAGRAPH, ['kind' => 'project']);
        $this->makeArticle('two', 'Two', self::PARAGRAPH, ['kind' => 'guide', 'page_builder' => [['id' => 'x', 'type' => 'long_form', 'enabled' => true]]]);

        $finder = app(PatternFinder::class);
        $schema = $this->schema('articles');

        $this->assertSame(['long_form'], $finder->find('articles', $schema, null, ['kind' => 'guide'])['blocks']['page_builder']['sequence']);

        // Nothing matches yet, so the whole collection is the evidence.
        $this->assertSame(2, $finder->find('articles', $schema, null, ['kind' => 'newsletter'])['entries']);
    }

    public function test_the_brief_describes_what_is_used_and_names_the_rest(): void
    {
        foreach (['one', 'two', 'three'] as $slug) {
            $this->makeArticle($slug, ucfirst($slug), self::PARAGRAPH.' '.ucfirst($slug).'.');
        }

        $schema = $this->schema('articles');
        $text = app(SchemaDescriber::class)->describe($schema, app(PatternFinder::class)->find('articles', $schema));

        $this->assertStringContainsString('- `title` (short text, required)', $text);
        $this->assertStringContainsString('- `summary` (plain text). Shown in lists', $text);
        $this->assertStringContainsString('`long_form`: Long Form. Prose column, on 100% of entries', $text);
        $this->assertStringContainsString('- `content` (markdown)', $text);
        $this->assertStringContainsString('write `type: cards` and nothing else', $text);
        $this->assertStringContainsString('in this order: hero, long_form, cards, related', $text);
        $this->assertStringContainsString('Also available but not normally used here: `gallery` (Gallery)', $text);
        $this->assertStringContainsString('Never ask for their text: hero, cards, related', $text);

        // Settings that never change, and fields a person fills in, are left out.
        $this->assertStringNotContainsString('`width`', $text);
        $this->assertStringNotContainsString('featured_image', $text);
        $this->assertStringNotContainsString('`image`', $text);
    }

    public function test_a_draft_becomes_page_builder_data(): void
    {
        foreach (['one', 'two', 'three'] as $slug) {
            $this->makeArticle($slug, ucfirst($slug), self::PARAGRAPH.' '.ucfirst($slug).'.');
        }

        $schema = $this->schema('articles');

        $built = app(EntryBuilder::class)->build([
            'title' => 'New Piece',
            'summary' => "A summary\nover two lines.",
            'featured_image' => 'not/for/the/writer.jpg',
            'made_up' => 'nope',
            'page_builder' => [
                ['type' => 'hero'],
                ['type' => 'long_form', 'width' => 'Wide', 'content' => "## The Problem\n\nIt was **hard**.\n\n> Challenge *accepted*."],
                ['type' => 'cards', 'heading' => 'Where else', 'items' => [['text' => 'Property'], ['text' => 'Recipes']]],
                ['type' => 'related'],
                ['type' => 'carousel', 'caption' => 'No such block'],
            ],
        ], $schema, app(PatternFinder::class)->find('articles', $schema), ['kind' => 'guide']);

        $data = $built['data'];
        $blocks = $data['page_builder'];

        $this->assertSame('New Piece', $data['title']);
        $this->assertSame("A summary\nover two lines.", $data['summary']);
        $this->assertArrayNotHasKey('featured_image', $data);
        $this->assertArrayNotHasKey('made_up', $data);

        // The type's default, then the collection's house default.
        $this->assertSame('guide', $data['kind']);
        $this->assertSame('user-1', $data['author']);

        $this->assertSame(['hero', 'long_form', 'cards', 'related'], array_column($blocks, 'type'));
        $this->assertTrue($blocks[0]['enabled']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $blocks[0]['id']);

        // Markdown became a Bard document; the block quote became the
        // field's own pull-quote set; the label "Wide" became its key.
        $this->assertSame(['heading', 'paragraph', 'set'], array_column($blocks[1]['content'], 'type'));
        $this->assertSame([['type' => 'bold']], $blocks[1]['content'][1]['content'][1]['marks']);
        $this->assertSame(['type' => 'pullquote', 'text' => 'Challenge *accepted*.'], $blocks[1]['content'][2]['attrs']['values']);
        $this->assertSame('wide', $blocks[1]['width']);
        $this->assertTrue($blocks[1]['numbered']);

        // Cards are the same on every entry, so the copy is used and said so.
        $this->assertSame('Broader uses', $blocks[2]['heading']);
        $this->assertSame(['Property searches', 'Recipe selection'], array_column($blocks[2]['items'], 'text'));
        $this->assertArrayHasKey('id', $blocks[2]['items'][0]);
        $this->assertContains('Card Grid is the same on every entry here, so its usual content was used in place of what was drafted.', $built['notes']);

        // A boilerplate block written as its type alone gets its usual content.
        $this->assertSame('More articles', $blocks[3]['heading']);
        $this->assertSame(3, $blocks[3]['limit']);

        $this->assertCount(3, $built['notes']);
        $this->assertStringContainsString('"carousel" does not exist', implode(' ', $built['notes']));
    }

    public function test_a_plain_collection_needs_no_page_builder(): void
    {
        $schema = $this->schema('posts');

        $this->assertSame(['title' => 'text', 'intro' => 'richtext', 'content' => 'richtext'], array_column($schema, 'kind', 'handle'));

        $built = app(EntryBuilder::class)->build([
            'title' => 'A Post',
            'intro' => 'Stays **markdown**.',
            'content' => "## Heading\n\nA paragraph.\n\n- one\n- two",
        ], $schema, app(PatternFinder::class)->find('posts', $schema));

        // A Markdown field keeps its markdown; a Bard field gets a document.
        $this->assertSame('Stays **markdown**.', $built['data']['intro']);
        $this->assertSame(['heading', 'paragraph', 'bulletList'], array_column($built['data']['content'], 'type'));
        $this->assertSame([], $built['notes']);

        // With nothing published there is no pattern, and the brief says so.
        $text = app(SchemaDescriber::class)->describe($schema, app(PatternFinder::class)->find('posts', $schema));
        $this->assertStringContainsString('- `content` (markdown)', $text);
        $this->assertStringNotContainsString('usually build', $text);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function schema(string $collection): array
    {
        return app(SchemaReader::class)->read(Collection::findByHandle($collection)->entryBlueprint());
    }
}
