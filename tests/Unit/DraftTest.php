<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Unit;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Drafts\BardToMarkdown;
use NineteenNinetyFour\Ghostwriter\Drafts\Draft;
use NineteenNinetyFour\Ghostwriter\Drafts\MarkdownToBard;
use PHPUnit\Framework\TestCase;

class DraftTest extends TestCase
{
    public function test_it_reads_a_yaml_draft(): void
    {
        $draft = Draft::parse("title: What Does a Website Cost?\nsummary: Why quotes vary.\npage_builder:\n  - type: long_form\n    content: |\n      ## Why?\n\n      Because scope differs.\n");

        $this->assertSame('What Does a Website Cost?', $draft->title());
        $this->assertSame('long_form', $draft->data['page_builder'][0]['type']);
        $this->assertStringContainsString("## Why?\n\nBecause scope differs.", $draft->data['page_builder'][0]['content']);

        // Title 5, summary 3, content 4 (the heading mark is not a word); block types are not counted.
        $this->assertSame(12, $draft->wordCount());
    }

    public function test_it_unwraps_a_draft_the_model_put_in_a_code_fence(): void
    {
        $this->assertSame('Fenced', Draft::parse("```yaml\ntitle: Fenced\n```")->title());
    }

    public function test_it_explains_what_is_wrong_with_a_bad_draft(): void
    {
        foreach ([
            ['summary: No title', 'needs a title'],
            ["- just\n- a list", 'should be a list of fields'],
            ["title: A\n  bad: [indent", 'not valid YAML'],
        ] as [$raw, $message]) {
            try {
                Draft::parse($raw);
                $this->fail("Expected \"{$message}\".");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

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

    public function test_prose_that_breaks_yaml_quoting_is_still_read(): void
    {
        $draft = Draft::parse(implode("\n", [
            "title: 'How to Brief a Web Agency'",
            "intro: 'Send us something even if it's rough. Here's what to put in it:'",
            'summary: What to send: a page is plenty',
            'quote: "She said "go" and we went"',
            'blocks:',
            '  - type: text',
            "    heading: 'It's fine'",
            '    body: |',
            "      It's a block: nothing here is touched.",
            "      'Quoted' too.",
            "  - 'Don't skip this'",
        ]));

        $this->assertSame("Send us something even if it's rough. Here's what to put in it:", $draft->data['intro']);
        $this->assertSame('What to send: a page is plenty', $draft->data['summary']);
        $this->assertSame('She said "go" and we went', $draft->data['quote']);
        $this->assertSame("It's fine", $draft->data['blocks'][0]['heading']);
        $this->assertSame("It's a block: nothing here is touched.\n'Quoted' too.\n", $draft->data['blocks'][0]['body']);
        $this->assertSame("Don't skip this", $draft->data['blocks'][1]);
    }

    public function test_a_value_wrapped_over_lines_is_still_read(): void
    {
        $draft = Draft::parse(implode("\n", [
            'title: Who Owns What',
            'blocks:',
            '  - type: text',
            '    why: "Launch" and "Support" both answer it: whose name is on the code',
            '      and who holds the keys after launch.',
            '    notes: Set it out plainly: code, hosting, support',
            '      and how that sits alongside the relationship.',
            '    tint: blue',
        ]));

        $this->assertSame('"Launch" and "Support" both answer it: whose name is on the code and who holds the keys after launch.', $draft->data['blocks'][0]['why']);
        $this->assertSame('Set it out plainly: code, hosting, support and how that sits alongside the relationship.', $draft->data['blocks'][0]['notes']);
        $this->assertSame('blue', $draft->data['blocks'][0]['tint']);
    }
}
