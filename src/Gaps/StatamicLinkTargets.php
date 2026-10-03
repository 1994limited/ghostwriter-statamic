<?php

namespace NineteenNinetyFour\Ghostwriter\Gaps;

use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\LinkTarget;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\LinkTargets;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;

/**
 * The entries a link can point at on a Statamic site.
 *
 * - exists(): a `link` field's `entry::id` or `asset::container::path`,
 *   an `entries` field's IDs, and Bard's `statamic://entry::id` inline;
 *   null for an outside address, which isn't checked.
 * - search(): entries whose title or slug share the hint's words, best
 *   first: "contact page" finds Contact. No model.
 */
class StatamicLinkTargets implements LinkTargets
{
    /** Words that say nothing about which page is meant. */
    private const FILLER = ['page', 'the', 'a', 'an', 'to', 'our', 'link', 'us', 'of', 'for', 'and', 'on'];

    public function __construct(private ?string $site = null) {}

    public function exists(mixed $target, Field $field): ?bool
    {
        if (is_array($target)) {
            $answers = array_map(fn ($item) => $this->exists($item, $field), $target);

            return in_array(false, $answers, true) ? false : (in_array(true, $answers, true) ? true : null);
        }

        if (! is_string($target) || trim($target) === '') {
            return null;
        }

        $target = (string) preg_replace('#^statamic://#', '', trim($target));

        if (str_starts_with($target, 'entry::')) {
            return Entry::find(substr($target, 7)) !== null;
        }

        if (str_starts_with($target, 'asset::')) {
            return Asset::find(substr($target, 7)) !== null;
        }

        // An entries field holds bare IDs.
        if ($field->type === 'entries' && ! str_contains($target, '/') && ! str_contains($target, ':')) {
            return Entry::find($target) !== null;
        }

        return null;
    }

    public function search(string $hint, int $limit = 3): array
    {
        $words = $this->words($hint);

        if ($words === []) {
            return [];
        }

        $scored = [];

        foreach ($this->entries() as $entry) {
            $title = (string) $entry->get('title');
            $have = array_unique([...$this->words($title), ...$this->words((string) $entry->slug())]);
            $shared = count(array_intersect($words, $have));

            if ($shared === 0) {
                continue;
            }

            // Every word of the hint found, then the fewest extra words.
            $scored[] = [$shared / count($words), -count(array_diff($have, $words)), $entry];
        }

        usort($scored, fn (array $a, array $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        return array_map(
            fn (array $row) => new LinkTarget('entry::'.$row[2]->id(), (string) $row[2]->get('title'), $row[2]->url()),
            array_slice($scored, 0, $limit),
        );
    }

    /**
     * @return iterable<EntryContract>
     */
    private function entries(): iterable
    {
        $query = Entry::query()->where('published', true);

        if ($this->site !== null) {
            $query->where('site', $this->site);
        }

        return $query->get()->filter(fn (EntryContract $entry) => $entry->url() !== null);
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_diff($words, self::FILLER)));
    }
}
