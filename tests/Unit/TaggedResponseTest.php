<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Unit;

use NineteenNinetyFour\Ghostwriter\Ai\TaggedResponse;
use PHPUnit\Framework\TestCase;

class TaggedResponseTest extends TestCase
{
    public function test_it_separates_the_reply_from_the_document(): void
    {
        $response = TaggedResponse::parse("<reply>\nHere you go.\n</reply>\n<draft>\n---\ntitle: A\n---\n\n## One\n</draft>", 'draft');

        $this->assertSame('Here you go.', $response->reply);
        $this->assertSame("---\ntitle: A\n---\n\n## One", $response->document);
    }

    public function test_a_reply_without_a_document_leaves_the_document_null(): void
    {
        $response = TaggedResponse::parse('<reply>1. What did it cost?</reply>', 'draft');

        $this->assertSame('1. What did it cost?', $response->reply);
        $this->assertNull($response->document);
    }

    public function test_a_document_cut_off_before_its_closing_tag_is_still_kept(): void
    {
        $response = TaggedResponse::parse("<reply>Draft below.</reply>\n<draft>\n---\ntitle: A\n---\n\n## One\n\nIt stops he", 'draft');

        $this->assertStringEndsWith('It stops he', $response->document);
    }

    public function test_text_outside_the_format_becomes_the_reply(): void
    {
        $response = TaggedResponse::parse('I could not follow the format, sorry.', 'draft');

        $this->assertSame('I could not follow the format, sorry.', $response->reply);
        $this->assertNull($response->document);
    }
}
