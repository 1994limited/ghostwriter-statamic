<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkIndex;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\LinkIndexContract;
use NineteenNinetyFour\Ghostwriter\Suggest\FileEntryIndex;
use NineteenNinetyFour\Ghostwriter\Tests\Concerns\LinkSites;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Entry;

/**
 * Core's LinkIndexContract against the addon's index: pages saved through
 * Statamic, indexed by its own save hooks. Pages is Ghostwriter's
 * collection; Information and Events aren't, so their pages get link rows.
 */
final class LinkIndexTest extends TestCase
{
    use LinkIndexContract, LinkSites;

    private DateTimeImmutable $scheduled;

    protected function setUp(): void
    {
        parent::setUp();

        $this->linkSites();
        $this->scheduled = new DateTimeImmutable('+10 days midnight');

        $this->saveEntry('design', 'site_pages', 'Garden design', ['summary' => 'A full design for your garden.', 'body' => self::ABOUT]);
        $this->saveEntry('about', 'site_info', 'About our garden design studio', ['body' => self::ABOUT]);
        $this->saveEntry('contact', 'site_info', 'Contact us', ['summary' => 'Book a garden design consultation or ask us a question.']);
        $this->saveEntry('draft', 'site_info', 'Garden design draft notes', published: false);
        $this->saveEntry('open-day', 'site_events', 'Garden design open day', ['summary' => 'Visit the studio.'], date: $this->scheduled->format('Y-m-d'));
        $this->saveEntry('offer', 'site_info', 'Garden design offer', ['noindex' => true]);
        $this->saveEntry('search', 'site_info', 'Search garden design');
        $this->saveEntry('about-cy', 'site_info', 'About our garden design studio', ['body' => self::ABOUT], 'cy', slug: 'about');
    }

    protected function tearDown(): void
    {
        $this->tearDownLinkSites();

        parent::tearDown();
    }

    protected function linkIndex(): LinkIndex
    {
        return app(LinkIndex::class);
    }

    protected function entryIndex(): EntryIndex
    {
        return app(EntryIndex::class);
    }

    protected function linkEntries(): array
    {
        return [
            'design' => new EntryRef('site_pages', 'design', 'default'),
            'about' => new EntryRef('site_info', 'about', 'default'),
            'contact' => new EntryRef('site_info', 'contact', 'default'),
            'draft' => new EntryRef('site_info', 'draft', 'default'),
            'scheduled' => new EntryRef('site_events', 'open-day', 'default'),
            'noindex' => new EntryRef('site_info', 'offer', 'default'),
            'search' => new EntryRef('site_info', 'search', 'default'),
            'home' => null,
            'other' => new EntryRef('site_info', 'about-cy', 'cy'),
        ];
    }

    protected function draftGroup(): string
    {
        return 'site_pages';
    }

    protected function scheduledFrom(): DateTimeImmutable
    {
        return $this->scheduled;
    }

    protected function retitle(EntryRef $entry, string $title): void
    {
        Entry::find((string) $entry->id)->set('title', $title)->save();
    }

    protected function deleteEntry(EntryRef $entry): void
    {
        Entry::find((string) $entry->id)->delete();
    }

    protected function hasRevisitRow(EntryRef $entry): bool
    {
        return app(RevisitStore::class)->get($entry) !== null;
    }

    public function test_a_page_linking_to_the_page_ranks_higher_for_it(): void
    {
        $this->saveEntry('process', 'site_pages', 'How we work', ['body' => 'Every project starts the same way. '.str_repeat('We listen, we walk the garden and we take notes. ', 3).'Read [about our studio](statamic://entry::design) first.']);
        $process = app(FileEntryIndex::class)->row(new EntryRef('site_pages', 'process', 'default'));

        $this->assertNotNull($process);
        $this->assertContains('entry::design', $process->links, 'A full row keeps where the page links.');

        $keys = array_map(fn ($entry) => $entry->entry?->key(), $this->linkIndex()->related("Opening hours\n\nClosed on Mondays.", 'site_pages', 'default', new EntryRef('site_pages', 'design', 'default')));
        $this->assertSame('site_pages:process@default', $keys[0], 'It links to the page: first, though it shares no words.');
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(FileEntryIndex::class, $this->linkIndex());
    }
}
