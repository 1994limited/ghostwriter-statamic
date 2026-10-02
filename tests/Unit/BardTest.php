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
}
