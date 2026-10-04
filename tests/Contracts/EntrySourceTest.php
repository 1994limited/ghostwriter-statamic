<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use Illuminate\Support\Carbon;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\EntrySourceContract;
use NineteenNinetyFour\Ghostwriter\Suggest\StatamicEntrySource;
use NineteenNinetyFour\Ghostwriter\Tests\Concerns\SuggestSites;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Entry;

/**
 * Core's EntrySourceContract against StatamicEntrySource: the published
 * entries of Ghostwriter's collections, one site at a time.
 */
final class EntrySourceTest extends TestCase
{
    use EntrySourceContract, SuggestSites;

    /** @var array{old: EntryRef, recent: EntryRef, draft: EntryRef, other: EntryRef, site: int|string, between: \DateTimeImmutable} */
    private array $entries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->twoSites();
        $now = Carbon::now();

        $save = function (string $id, string $site, bool $published, Carbon $updated): EntryRef {
            Entry::make()->id($id)->collection('site_pages')->locale($site)->slug($id)->published($published)->data([
                'title' => ucfirst($id),
                'body' => 'A full design for your garden from our team of designers, with a planting plan for every border.',
                'updated_at' => $updated->getTimestamp(),
            ])->save();

            return new EntryRef('site_pages', $id, $site);
        };

        $this->entries = [
            'old' => $save('old', 'default', true, $now->copy()->subDays(400)),
            'recent' => $save('recent', 'default', true, $now->copy()->subDay()),
            'draft' => $save('draft', 'default', false, $now->copy()->subDay()),
            'other' => $save('other', 'cy', true, $now->copy()->subDay()),
            'site' => 'default',
            'between' => $now->copy()->subDays(30)->toDateTimeImmutable(),
        ];
    }

    protected function entrySource(): EntrySource
    {
        return app(EntrySource::class);
    }

    protected function sourceEntries(): array
    {
        return $this->entries;
    }

    public function test_the_addon_binds_its_own(): void
    {
        $this->assertInstanceOf(StatamicEntrySource::class, $this->entrySource());
    }
}
