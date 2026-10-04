<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\EntryIndexContract;
use NineteenNinetyFour\Ghostwriter\Suggest\FileEntryIndex;
use NineteenNinetyFour\Ghostwriter\Tests\Concerns\SuggestSites;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Entry;

/**
 * Core's EntryIndexContract against the addon's index beside the revisit
 * shards, filled by its own save hook: four entries saved through
 * Statamic, two sites.
 */
final class EntryIndexTest extends TestCase
{
    use EntryIndexContract, SuggestSites;

    /** @var array{design: EntryRef, planting: EntryRef, journal: EntryRef, other: EntryRef} */
    private array $indexed;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ghostwriter.revisit.on_save' => true]);

        $this->twoSites();

        $save = function (string $id, string $collection, string $site, string $title, string $summary, string $body): EntryRef {
            Entry::make()->id($id)->collection($collection)->locale($site)->slug($id)->published(true)->data(['title' => $title, 'summary' => $summary, 'body' => $body])->save();

            return new EntryRef($collection, $id, $site);
        };

        $this->indexed = [
            'design' => $save('design', 'site_pages', 'default', 'Garden design', 'Designs for whole gardens.', self::DESIGN),
            'planting' => $save('planting', 'site_pages', 'default', 'Planting plans', 'Planting plans for borders, pots and new gardens.', 'We draw planting plans for borders and pots, with every plant named and placed for the light it gets.'),
            'site_journal' => $save('corbridge', 'site_journal', 'default', 'A walled garden in Corbridge', 'Two years on from a garden we finished.', 'Two years on, the walled garden in Corbridge has filled out and the espaliers are fruiting.'),
            'other' => $save('design-cy', 'site_pages', 'cy', 'Garden design', 'Designs for whole gardens.', self::DESIGN),
        ];
    }

    protected function entryIndex(): EntryIndex
    {
        return app(EntryIndex::class);
    }

    protected function indexedEntries(): array
    {
        return $this->indexed;
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(FileEntryIndex::class, $this->entryIndex());
    }

    public function test_a_deleted_entry_leaves_the_index(): void
    {
        Entry::find('planting')->delete();

        $this->assertNotContains($this->indexed['planting']->key(), array_map(fn ($entry) => $entry->entry->key(), $this->entryIndex()->nearest($this->indexed['design'], 'Planting plans', 10)));
    }
}
