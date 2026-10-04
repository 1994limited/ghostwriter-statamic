<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Layout\InlineLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\LinkInsertContract;
use NineteenNinetyFour\Ghostwriter\Drafts\BardDialect;
use NineteenNinetyFour\Ghostwriter\Suggest\StatamicLinkSource;
use NineteenNinetyFour\Ghostwriter\Tests\Concerns\LinkSites;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Core's LinkInsertContract on Bard (SEO layer §7.4, §20): a link the SEO
 * pass inserts as `statamic://entry::id` goes through the real apply path
 * (core's EntryBuilder with MarkdownToBard, the form's pre-processing and
 * saving), reads back through BardDialect as the same link beside the
 * markers, and Statamic renders it as the Contact page's address.
 */
final class LinkInsertTest extends TestCase
{
    use LinkInsertContract, LinkSites;

    protected function setUp(): void
    {
        parent::setUp();

        $this->linkSites();
        $this->saveEntry('contact', 'site_info', 'Contact us', ['summary' => 'Book a garden design consultation.']);

        Collection::make('visits')->title('Visits')->routes('/visits/{slug}')->save();
        Blueprint::make('visit')->setNamespace('collections.visits')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'buttons' => ['h2', 'h3', 'bold', 'italic', 'anchor']]],
        ]])->save();
    }

    protected function tearDown(): void
    {
        $this->tearDownLinkSites();

        parent::tearDown();
    }

    protected function inlineLinks(): InlineLinks
    {
        return new StatamicLinks;
    }

    protected function linkTarget(): DigestEntry
    {
        return app(StatamicLinkSource::class)->row(Entry::find('contact'))->digest();
    }

    protected function linkField(): Field
    {
        return app(SchemaReader::class)->schema(Collection::findByHandle('visits')->entryBlueprint())->field('body');
    }

    protected function storedMarkdown(string $markdown, Field $field): string
    {
        return (string) app(BardDialect::class)->toMarkdown($this->saved($markdown), $field);
    }

    protected function renderedHref(string $href): ?string
    {
        $entry = Entry::make()->collection('visits')->slug('e2e-visit')->data(['title' => 'A visit', 'body' => $this->saved("Do [tell us about your garden]({$href}).")]);
        $html = (string) $entry->augmentedValue('body')->value();

        return preg_match('/<a [^>]*href="([^"]+)"[^>]*>tell us about your garden<\/a>/', $html, $m) === 1 ? html_entity_decode($m[1]) : null;
    }

    protected function targetUrl(): string
    {
        return (string) Entry::find('contact')->url();
    }

    /**
     * Markdown into the Bard field as "Use this draft" builds it, into the
     * publish form, and saved from it.
     *
     * @return array<int, mixed>
     */
    private function saved(string $markdown): array
    {
        $blueprint = Collection::findByHandle('visits')->entryBlueprint();
        $schema = app(SchemaReader::class)->schema($blueprint);
        $built = app(EntryLayouts::class)->build(['title' => 'A visit', 'body' => $markdown], $schema)->data;
        $form = $blueprint->fields()->addValues($built)->preProcess()->values()->all();

        return $blueprint->fields()->addValues($form)->process()->values()->all()['body'] ?? [];
    }
}
