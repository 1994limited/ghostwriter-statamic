<?php

namespace NineteenNinetyFour\Ghostwriter\Suggest;

use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\RowKind;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term as Terms;
use Statamic\Fields\Blueprint;
use Throwable;

/**
 * The site's routable pages as the link index keeps them (SEO layer §7.1):
 * every collection with a route for the site, Ghostwriter's or not, and
 * taxonomy terms whose taxonomy has a template and whose term has its own
 * text (40 words or more). It lists a site's groups and pages (keys and
 * dates only) for the daily pass, and turns one entry or term into an
 * IndexRow. Core's Seo\Linkable decides what's left out.
 *
 * - Published: `published()`; a scheduled entry (a dated collection whose
 *   future entries are private) is kept with `liveFrom`, an expiring one
 *   (past entries private) with `liveUntil`.
 * - Links: `entry::{id}` (Bard writes `statamic://entry::{id}`); terms by
 *   their address, as Bard's link picker offers no term reference.
 * - Key pages: entries in any navigation tree, and the top level of a
 *   structured collection.
 * - noindex: StatamicSeoFields::noindex() (SEO Pro's robots through the
 *   cascade, or a plain noindex toggle).
 *
 * Nothing here calls a model or saves anything.
 */
class StatamicLinkSource
{
    /** Words of its own text a term needs to be worth linking to. */
    public const TERM_WORDS = 40;

    /** Groups of terms are named apart from collections: "taxonomy:tags". */
    public const TAXONOMY = 'taxonomy:';

    /** @var array<string, array<string, true>> Key page entry IDs, by site. */
    private array $keys = [];

    /** @var array<string, Schema> */
    private array $schemas = [];

    public function __construct(
        private TypeRepository $types,
        private SchemaReader $reader,
        private StatamicSeoFields $seo,
        private EntryChecks $checks,
    ) {}

    /**
     * Every routable group of a site, by group name: collections with a
     * route for it, and taxonomies with a term template.
     *
     * @return array<string, array{kind: RowKind, handle: string, label: string}>
     */
    public function groups(string $site): array
    {
        $groups = [];

        foreach (Collection::all() as $collection) {
            try {
                if (in_array($site, $collection->sites()->all(), true) && $collection->route($site) !== null) {
                    $groups[$collection->handle()] = ['kind' => RowKind::Entry, 'handle' => $collection->handle(), 'label' => (string) $collection->title()];
                }
            } catch (Throwable) {
                continue;
            }
        }

        foreach (Taxonomy::all() as $taxonomy) {
            try {
                if (in_array($site, $taxonomy->sites()->all(), true) && view()->exists((string) $taxonomy->termTemplate())) {
                    $groups[self::TAXONOMY.$taxonomy->handle()] = ['kind' => RowKind::Term, 'handle' => $taxonomy->handle(), 'label' => (string) $taxonomy->title()];
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $groups;
    }

    /** Whether Ghostwriter writes for a group: its pages have full rows. */
    public function ghostwriters(string $group): bool
    {
        return ! str_starts_with($group, self::TAXONOMY) && $this->types->enabled($group);
    }

    /**
     * The linkable pages of some of a site's groups (published or
     * scheduled, with an address), keys and dates only, for LinkPlan.
     *
     * @param  array<string, array{kind: RowKind, handle: string, label: string}>  $groups
     * @return \Generator<int, array{key: string, group: string, updated: ?string, key_page: bool}>
     */
    public function pages(string $site, array $groups): \Generator
    {
        $keys = $this->keyPages($site);

        foreach ($groups as $group => $info) {
            if ($info['kind'] === RowKind::Term) {
                foreach (Terms::query()->where('taxonomy', $info['handle'])->get() as $term) {
                    $term = $term->in($site);

                    if ($term !== null && $this->termLinkable($term)) {
                        yield ['key' => self::ref($term)->key(), 'group' => $group, 'updated' => self::updated($term), 'key_page' => false];
                    }
                }

                continue;
            }

            foreach (Entries::query()->where('collection', $info['handle'])->where('site', $site)->get() as $entry) {
                if ($entry->published() && self::address($entry) !== null) {
                    yield ['key' => self::ref($entry)->key(), 'group' => $group, 'updated' => self::updated($entry), 'key_page' => isset($keys[(string) $entry->id()])];
                }
            }
        }
    }

    /** The entry or term a reference is to, in its site; null when it's gone. */
    public function find(EntryRef $ref): Entry|Term|null
    {
        $site = (string) ($ref->site ?? Site::default()->handle());

        if (str_starts_with($ref->group, self::TAXONOMY)) {
            $term = Terms::find((string) $ref->id);

            return $term?->in($site);
        }

        $entry = Entries::find((string) $ref->id);

        if ($entry !== null && $entry->locale() !== $site) {
            $entry = $entry->in($site);
        }

        return $entry;
    }

    /** An entry's or term's row, in its own site; Linkable::keep() is false for a draft or a page with no address. */
    public function row(Entry|Term $item, ?IndexScope $scope = null): IndexRow
    {
        if ($item instanceof Term) {
            return $this->termRow($item);
        }

        $site = (string) ($item->locale() ?: Site::default()->handle());
        $collection = $item->collection();
        $status = (string) $item->status();
        $date = $collection?->dated() && $item->date() ? $item->date()->toAtomString() : null;
        [$noindex, $description] = $this->seo($item->blueprint(), $item->data()->all(), (string) $item->collectionHandle(), (string) $item->id(), $site);

        return IndexRow::make(
            entry: self::ref($item),
            scope: $scope ?? ($this->ghostwriters((string) $item->collectionHandle()) ? IndexScope::Full : IndexScope::Link),
            title: (string) ($item->get('title') ?? ''),
            url: self::address($item),
            summary: $description ?? $this->summary($item->data()->all(), $item->blueprint()),
            type: (string) ($collection?->title() ?? $item->collectionHandle()),
            kind: RowKind::Entry,
            liveFrom: $collection?->futureDateBehavior() === 'private' ? $date : null,
            liveUntil: $collection?->pastDateBehavior() === 'private' ? $date : null,
            noindex: $noindex,
            key: isset($this->keyPages($site)[(string) $item->id()]),
            link: 'entry::'.$item->id(),
            updated: (string) self::updated($item),
            published: (bool) $item->published() && $status !== 'draft',
            indexed: now()->toAtomString(),
            locale: $this->checks->language($site),
        );
    }

    /**
     * The forms a link to a page is stored in, so pages linking to it are
     * checked again when it's deleted.
     *
     * @return list<string>
     */
    public static function targets(Entry|Term $item): array
    {
        if ($item instanceof Term) {
            $url = self::address($item);

            return $url === null ? [] : [$url];
        }

        return ['entry::'.$item->id(), 'statamic://entry::'.$item->id()];
    }

    public static function ref(Entry|Term $item): EntryRef
    {
        if ($item instanceof Term) {
            return new EntryRef(self::TAXONOMY.$item->taxonomyHandle(), (string) $item->id(), $item->locale() ?: null);
        }

        return EntryChecks::ref($item);
    }

    /**
     * Entry IDs of a site's key pages: in any navigation tree, or at the
     * top level of a structured collection.
     *
     * @return array<string, true>
     */
    public function keyPages(string $site): array
    {
        if (isset($this->keys[$site])) {
            return $this->keys[$site];
        }

        $ids = [];
        $walk = function (array $items, bool $deep) use (&$walk, &$ids): void {
            foreach ($items as $item) {
                if (is_array($item) && is_string($item['entry'] ?? null)) {
                    $ids[$item['entry']] = true;
                }

                if ($deep && is_array($item['children'] ?? null)) {
                    $walk($item['children'], true);
                }
            }
        };

        try {
            foreach (Nav::all() as $nav) {
                $walk($nav->in($site)?->tree() ?? [], true);
            }

            foreach (Collection::all() as $collection) {
                if ($collection->hasStructure()) {
                    $walk($collection->structure()->in($site)?->tree() ?? [], false);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $this->keys[$site] = $ids;
    }

    /** Forget what was read this request (after a tree changed). */
    public function forget(): void
    {
        $this->keys = [];
    }

    /**
     * An entry's address in its site, relative ('/journal/a-post', '/' for
     * the home page); null with no route or for a redirect.
     */
    public static function address(Entry|Term $item): ?string
    {
        try {
            if ($item instanceof Entry && $item->isRedirect()) {
                return null;
            }

            $uri = $item->uri();
        } catch (Throwable) {
            return null;
        }

        if (! is_string($uri) || $uri === '') {
            return null;
        }

        return '/'.ltrim($uri, '/');
    }

    private function termRow(Term $term): IndexRow
    {
        $site = (string) ($term->locale() ?: Site::default()->handle());
        $taxonomy = $term->taxonomy();
        [$noindex, $description] = $this->seo($term->blueprint(), $term->data()->all(), self::TAXONOMY.$term->taxonomyHandle(), (string) $term->id(), $site);

        return IndexRow::make(
            entry: self::ref($term),
            scope: IndexScope::Link,
            title: (string) ($term->get('title') ?? $term->title() ?? ''),
            url: $this->termLinkable($term) ? self::address($term) : null,
            summary: $description ?? $this->summary($term->data()->all(), $term->blueprint()),
            type: (string) ($taxonomy?->title() ?? $term->taxonomyHandle()),
            kind: RowKind::Term,
            noindex: $noindex,
            link: self::address($term),
            updated: (string) self::updated($term),
            published: (bool) $term->published(),
            indexed: now()->toAtomString(),
            locale: $this->checks->language($site),
        );
    }

    /** A term worth linking to: routed, published, with 40 words of its own. */
    private function termLinkable(Term $term): bool
    {
        if (! $term->published() || self::address($term) === null) {
            return false;
        }

        return count(preg_split('/\s+/u', trim($this->text($term->data()->except(['title', 'slug'])->all())), -1, PREG_SPLIT_NO_EMPTY) ?: []) >= self::TERM_WORDS;
    }

    /**
     * Whether SEO Pro or a field asks search engines not to index the page,
     * and its own SEO description where it has one.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: bool, 1: ?string}
     */
    private function seo(?Blueprint $blueprint, array $data, string $group, string $id, string $site): array
    {
        if ($blueprint === null) {
            return [false, null];
        }

        try {
            $key = $blueprint->namespace().'::'.$blueprint->handle();
            $schema = $this->schemas[$key] ??= $this->reader->schemaWithoutFolders($blueprint);
            $entry = new EntryData(values: $data, id: $id, group: $group, site: $site);
            $description = null;

            foreach ($this->seo->in($schema, $entry) as $field) {
                if ($field->role === SeoField::DESCRIPTION && $field->text !== null && trim($field->text) !== '') {
                    $description = trim($field->text);
                    break;
                }
            }

            return [$this->seo->noindex($schema, $entry) === true, $description];
        } catch (Throwable $exception) {
            report($exception);

            return [false, null];
        }
    }

    /**
     * A page's summary for the digest: a summary-like field, else its first
     * paragraph of eight words or more.
     *
     * @param  array<string, mixed>  $data
     */
    private function summary(array $data, ?Blueprint $blueprint): string
    {
        foreach (['summary', 'excerpt', 'intro', 'meta_description', 'description'] as $handle) {
            if (is_string($data[$handle] ?? null) && trim(strip_tags($data[$handle])) !== '') {
                return mb_substr(trim(strip_tags($data[$handle])), 0, DigestEntry::SUMMARY);
            }
        }

        $fields = $blueprint?->fields()->all()->filter(fn ($field) => in_array($field->type(), ['bard', 'markdown', 'textarea', 'text', 'replicator', 'grid', 'group'], true))->keys()->all() ?? array_keys($data);

        foreach ($fields as $handle) {
            if (in_array($handle, ['title', 'slug'], true) || ! isset($data[$handle])) {
                continue;
            }

            foreach (preg_split('/\n+/u', $this->text($data[$handle])) ?: [] as $line) {
                if (count(preg_split('/\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: []) >= 8) {
                    return mb_substr(trim($line), 0, DigestEntry::SUMMARY);
                }
            }
        }

        return '';
    }

    /** Plain text of a value as stored: a string, Bard's nodes, a set's fields; one line per block. */
    private function text(mixed $value): string
    {
        if (is_string($value)) {
            return trim(html_entity_decode(strip_tags((string) preg_replace('/<\/(p|h\d|li|div)>/i', "\n", $value)), ENT_QUOTES | ENT_HTML5));
        }

        if (! is_array($value)) {
            return '';
        }

        if (($value['type'] ?? null) === 'text' && is_string($value['text'] ?? null)) {
            return $value['text'];
        }

        if (is_string($value['type'] ?? null) && is_array($value['content'] ?? null)) {
            $inline = in_array($value['type'], ['paragraph', 'heading'], true);

            return implode($inline ? '' : "\n", array_map(fn ($child) => $this->text($child), $value['content'])).($inline ? "\n" : '');
        }

        $parts = [];

        foreach ($value as $key => $child) {
            if (in_array($key, ['type', 'id', 'url', 'href', 'src', 'link', 'enabled', 'attrs', 'marks'], true)) {
                continue;
            }

            $text = $this->text($child);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    private static function updated(Entry|Term $item): ?string
    {
        try {
            return $item->lastModified()?->toAtomString();
        } catch (Throwable) {
            return null;
        }
    }
}
