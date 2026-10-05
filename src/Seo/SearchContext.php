<?php

namespace NineteenNinetyFour\Ghostwriter\Seo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Seo\MetaContext;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoProvenance;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SlugContext;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Throwable;

/**
 * What core's SEO pass needs for a Statamic draft's search title,
 * description and address (Seo\MetaContext, SEO layer §9, §10):
 *
 * - the SEO fields (StatamicSeoFields: SEO Pro and plain fields) in the
 *   entry's whole blueprint, SEO Pro's injected `seo` field included;
 * - the entry's values (the entry being edited, as saved; a new entry has
 *   none, so SEO Pro's collection and site defaults apply), with its
 *   collection and site;
 * - whether it is new, or not published (Statamic keeps no record of an
 *   entry having been published before, so an unpublished one counts);
 * - what Ghostwriter wrote into it before: the `written` hashes of every
 *   session on the same entry;
 * - where its address goes (slug()).
 *
 * Nothing here calls a model or writes anything.
 */
class SearchContext
{
    /** How long the slugs in use and other sessions' hashes are kept: the panel asks again every few seconds. */
    private const SECONDS = 30;

    /** Matches the slug in a collection's route: `{slug}` or `{{ slug }}`. */
    private const SLUG_TOKEN = '/\{\{?\s*slug\s*\}?\}/';

    public function __construct(
        private SchemaReader $reader,
        private SeoFields $fields,
        private SessionStore $sessions,
        private EntryGaps $gaps,
    ) {}

    /**
     * The MetaContext for a session's draft; null when its collection or
     * blueprint has gone.
     */
    public function for(Session $session, ContentType $type, ?Blueprint $blueprint = null, ?ContentKind $kind = null, ?string $voice = null): ?MetaContext
    {
        $entry = self::entryOf($session);
        $collection = $entry?->collection() ?? TypeRepository::collectionOf($type);
        $blueprint = $entry?->blueprint() ?? $blueprint ?? TypeRepository::blueprintOf($type);

        if (! $collection instanceof Collection || ! $blueprint) {
            return null;
        }

        $site = $entry?->locale() ?: Site::default()->handle();
        $values = $entry ? $this->gaps->data($blueprint, [], $entry) : [];

        return new MetaContext(
            fields: $this->fields,
            schema: $this->reader->schemaWithoutFolders($blueprint),
            entry: new EntryData($values, $entry?->id(), group: $collection->handle(), site: $site),
            newEntry: self::isNew($entry),
            provenance: $entry ? $this->provenance($entry, $session) : new SeoProvenance,
            slug: $this->slug($collection, $site, $entry, $session),
            kind: $kind ?? $type->toStudio(),
            voice: $voice ?? '',
            locale: (string) (Site::get($site)?->locale() ?? 'en'),
        );
    }

    /**
     * Where the address goes (SEO layer §10): only in a collection whose
     * route has the slug in it. Settable on a new entry, or one not
     * published whose slug is empty, still the one Statamic made from its
     * title, or the one Ghostwriter set; never on a published entry or a
     * slug someone typed. Unique in its collection and site.
     */
    public function slug(Collection $collection, string $site, ?Entry $entry, ?Session $session = null): ?SlugContext
    {
        try {
            $route = (string) $collection->route($site);
        } catch (Throwable) {
            return null;
        }

        if (! $collection->requiresSlugs() || preg_match(self::SLUG_TOKEN, $route, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $current = $entry ? (string) $entry->slug() : null;
        $ours = $session !== null ? SeoState::of($session)->meta->slug : null;

        return new SlugContext(
            settable: $entry === null || (! $entry->published() && self::generated($current, (string) $entry->get('title'), $ours, $site)),
            dated: $collection->dated(),
            taken: $this->taken($collection, $site, $entry),
            current: $current !== '' ? $current : null,
            base: self::base($route, (int) $match[0][1], $site),
        );
    }

    /**
     * Whether a slug is one nobody typed: empty, the one Statamic makes
     * from the title, or the one Ghostwriter set.
     */
    public static function generated(?string $slug, string $title, ?string $ours, string $site): bool
    {
        $slug = trim((string) $slug);

        return $slug === '' || $slug === $ours || $slug === Str::slug($title, '-', Site::get($site)?->lang() ?? 'en') || $slug === Str::slug($title);
    }

    /** The entry a session edits, or the one it became. */
    public static function entryOf(Session $session): ?Entry
    {
        $id = $session->source ?? $session->recordId;

        try {
            return $id !== null ? Entries::find((string) $id) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** New, or not published: Statamic keeps no record of an entry published before. */
    public static function isNew(?Entry $entry): bool
    {
        return $entry === null || ! $entry->published();
    }

    /**
     * What Ghostwriter wrote into this entry before: the hashes every
     * session on it kept when its draft was used.
     */
    private function provenance(Entry $entry, Session $session): SeoProvenance
    {
        $id = (string) $entry->id();

        try {
            $hashes = Cache::remember('ghostwriter.seo.written.'.sha1($id.'|'.$session->id), self::SECONDS, function () use ($id, $session) {
                $written = new SeoProvenance;

                foreach ($this->sessions->all() as $other) {
                    if ($other->id !== $session->id && ((string) $other->source === $id || (string) $other->recordId === $id)) {
                        $written = $written->merge(SeoState::of($other)->written);
                    }
                }

                return $written->toArray();
            });
        } catch (Throwable) {
            // Without them, earlier text reads as a person's: only suggested.
            return new SeoProvenance;
        }

        return SeoProvenance::fromArray(is_array($hashes) ? $hashes : []);
    }

    /**
     * The slugs in use in the collection on this site, the entry's own aside.
     *
     * @return list<string>
     */
    private function taken(Collection $collection, string $site, ?Entry $entry): array
    {
        try {
            $taken = Cache::remember('ghostwriter.seo.taken.'.sha1($collection->handle().'|'.$site.'|'.$entry?->id()), self::SECONDS, function () use ($collection, $site, $entry) {
                $query = Entries::query()->where('collection', $collection->handle())->where('site', $site);

                if ($entry) {
                    $query->where('id', '!=', $entry->id());
                }

                return array_values(array_unique(array_filter(array_map(fn ($slug) => is_string($slug) ? $slug : '', $query->pluck('slug')->all()))));
            });
        } catch (Throwable) {
            return [];
        }

        return is_array($taken) ? array_values(array_filter($taken, 'is_string')) : [];
    }

    /**
     * What comes before the slug in the address, for the Search section:
     * the site's host and the route up to the slug, any other part of it
     * (`{parent_uri}`, `{year}`) shown as "…": "northfold.garden/journal/".
     */
    private static function base(string $route, int $at, string $site): string
    {
        $prefix = (string) preg_replace('/\{\{?\s*[A-Za-z_:]+\s*\}?\}/', '…', substr($route, 0, $at));

        try {
            $url = (string) (Site::get($site)?->absoluteUrl() ?? url('/'));
        } catch (Throwable) {
            $url = '';
        }

        $host = rtrim((string) preg_replace('#^https?://#', '', $url), '/');

        return $host.'/'.ltrim($prefix, '/');
    }
}
