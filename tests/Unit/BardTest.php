<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Unit;

use NineteenNinetyFour\Ghostwriter\Drafts\BardToMarkdown;
use NineteenNinetyFour\Ghostwriter\Drafts\MarkdownToBard;
use PHPUnit\Framework\TestCase;

/**
 * The Bard conversions. Drafts, lenient YAML and scrubbing are core's, and
 * tested there.
 */
class BardTest extends TestCase
{
    public function test_markdown_becomes_a_bard_document_and_back(): void
    {
        $markdown = "## Heading\n\nA **bold** and *italic* [link](https://example.com).\n\n- one\n- two\n\n1. first\n2. second\n\n> Quoted.\n\n| A | B |\n| --- | --- |\n| 1 | 2 |";

        $nodes = (new MarkdownToBard)->convert($markdown);

        $this->assertSame(['heading', 'paragraph', 'bulletList', 'orderedList', 'blockquote', 'table'], array_column($nodes, 'type'));
        $this->assertSame([['type' => 'bold']], $nodes[1]['content'][1]['marks']);
        $this->assertSame('https://example.com', $nodes[1]['content'][5]['marks'][0]['attrs']['href']);
        $this->assertSame(['tableHeader', 'tableHeader'], array_column($nodes[5]['content'][0]['content'], 'type'));

        $this->assertSame($markdown, (new BardToMarkdown)->convert($nodes));
    }

    public function test_a_stored_pull_quote_set_reads_back_as_a_block_quote(): void
    {
        $markdown = (new BardToMarkdown)->convert([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before.']]],
            ['type' => 'set', 'attrs' => ['id' => 'x', 'values' => ['type' => 'pullquote', 'text' => 'Challenge accepted.']]],
            ['type' => 'set', 'attrs' => ['id' => 'y', 'values' => ['type' => 'image', 'image' => 'photo.jpg']]],
        ]);

        $this->assertSame("Before.\n\n> Challenge accepted.", $markdown);
    }

    public function test_list_items_keep_their_text_in_every_shape(): void
    {
        $nodes = json_decode(file_get_contents(__DIR__.'/../__fixtures__/bard/lists.json'), true);

        $this->assertSame(implode("\n", [
            '## What we care about',
            '',
            '- Plants that suit the soil',
            '- **Materials** that weather well',
            '- Gardens for [wildlife](https://example.com/wildlife)',
            '- Straight talk about',
            '  - cost',
            '  - *time*',
            '',
            '1. Survey',
            '2. Design',
            '   1. Sketch',
            '   2. Plan',
            '3. Build  ',
            '   and plant',
        ]), (new BardToMarkdown)->convert($nodes));
    }

    public function test_lists_survive_the_trip_to_markdown_and_back(): void
    {
        $nodes = json_decode(file_get_contents(__DIR__.'/../__fixtures__/bard/lists.json'), true);
        $markdown = (new BardToMarkdown)->convert($nodes);

        $bard = (new MarkdownToBard)->convert($markdown);

        $this->assertSame(['heading', 'bulletList', 'orderedList'], array_column($bard, 'type'));
        $this->assertCount(4, $bard[1]['content']);
        $this->assertSame('bulletList', $bard[1]['content'][3]['content'][1]['type']);
        $this->assertSame('orderedList', $bard[2]['content'][1]['content'][1]['type']);
        $this->assertSame(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Plants that suit the soil']]], $bard[1]['content'][0]['content'][0]);
        $this->assertSame($markdown, (new BardToMarkdown)->convert($bard));
    }

    public function test_an_item_with_more_than_one_paragraph_stays_one_item(): void
    {
        $markdown = (new BardToMarkdown)->convert([
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First.']]],
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second.']]],
                ]],
                ['type' => 'listItem', 'content' => []],
            ]],
        ]);

        $this->assertSame("- First.\n\n  Second.\n-", $markdown);

        $bard = (new MarkdownToBard)->convert($markdown);

        $this->assertCount(2, $bard[0]['content']);
        $this->assertSame(['paragraph', 'paragraph'], array_column($bard[0]['content'][0]['content'], 'type'));
        $this->assertSame([['type' => 'paragraph']], $bard[0]['content'][1]['content']);
    }

    public function test_inline_code_keeps_its_words(): void
    {
        $nodes = (new MarkdownToBard)->convert('Run `php please stache:clear` after.');

        $this->assertSame('Run php please stache:clear after.', implode('', array_column($nodes[0]['content'], 'text')));
    }
}
