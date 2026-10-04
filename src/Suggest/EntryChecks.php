<?php

namespace NineteenNinetyFour\Ghostwriter\Suggest;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\AgePolicy;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkResult;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Quieted;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestOptions;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;

/**
 * What Suggest edits' free checks read on a Statamic site: an entry's
 * values (as saved, or as its publish form holds them) in core's
 * CheckContext, with Finish this page's ports plus alt text and SEO
 * fields, the entry's age, its site's language, and the three settings.
 *
 * Nothing here calls a model or saves anything.
 */
class EntryChecks
{
    public function __construct(
        private EntryGaps $gaps,
        private Settings $settings,
        private TypeRepository $types,
        private StatamicAssetAlt $alt,
        private StatamicSeoFields $seo,
    ) {}

    public static function ref(Entry $entry): EntryRef
    {
        return new EntryRef((string) $entry->collectionHandle(), (string) $entry->id(), $entry->locale() ?: null);
    }

    /**
     * The checks' context for an entry.
     *
     * @param  array<string, mixed>|null  $data  As the entry stores it; the saved data when null.
     * @param  array<string, LinkResult>  $external  The weekly link check's results for its links.
     */
    public function context(Entry $entry, ?array $data = null, ?DateTimeImmutable $now = null, ?EntryIndex $index = null, ?Quieted $quieted = null, array $external = []): CheckContext
    {
        $blueprint = $entry->blueprint();
        $data ??= $this->saved($entry);
        $collection = (string) $entry->collectionHandle();
        $gaps = $this->gaps->context($blueprint, $data, $collection, (string) $entry->id(), site: $entry->locale());

        return new CheckContext(
            gaps: $this->withPorts($gaps),
            now: $now ?? Carbon::now()->toDateTimeImmutable(),
            updatedAt: self::updatedAt($entry),
            language: $this->language($entry->locale()),
            index: $index,
            entry: self::ref($entry),
            age: $this->age(),
            options: $this->options(),
            quieted: $quieted ?? new Quieted,
            external: $external,
        );
    }

    /**
     * What an entry holds, as the checks read it: its data, with the date
     * (which Statamic keeps on the entry).
     *
     * @return array<string, mixed>
     */
    public function saved(Entry $entry): array
    {
        return $this->gaps->data($entry->blueprint(), [], $entry);
    }

    /**
     * A publish form's values as the entry would store them.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function fromForm(Entry $entry, array $values): array
    {
        return $this->gaps->data($entry->blueprint(), $values, $entry);
    }

    public function withPorts(GapContext $gaps): GapContext
    {
        return new GapContext(
            schema: $gaps->schema,
            entry: $gaps->entry,
            richText: $gaps->richText,
            links: $gaps->links,
            placeholders: $gaps->placeholders,
            assets: $gaps->assets,
            targets: $gaps->targets,
            stock: $gaps->stock,
            pattern: $gaps->pattern,
            session: $gaps->session,
            sources: $gaps->sources,
            alt: $this->alt,
            seo: $this->seo,
        );
    }

    /**
     * Age counts a quarter in collections with dates (a journal), unless a
     * manager has said it counts in full there.
     */
    public function age(): AgePolicy
    {
        $dated = $this->types->collections()->filter(fn ($collection) => $collection->dated())->map->handle()->values()->all();

        return AgePolicy::fromGroups($dated, $this->settings->ageInFull());
    }

    public function options(): SuggestOptions
    {
        return new SuggestOptions(claims: $this->settings->checksClaims());
    }

    /**
     * The language the phrase checks read: the config's, or the site's
     * own ("en_GB", "de_DE").
     */
    public function language(?string $site = null): string
    {
        $configured = config('ghostwriter.suggest.language');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $found = $site ? Site::get($site) : Site::default();

        return (string) ($found?->locale() ?: $found?->lang() ?: config('app.locale', 'en'));
    }

    /** The hosts of the site itself: links to them aren't "other sites". */
    public static function ownHosts(): array
    {
        $hosts = [];

        foreach ([config('app.url'), ...Site::all()->map(fn ($site) => $site->absoluteUrl())->values()->all()] as $url) {
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    public static function updatedAt(Entry $entry): ?DateTimeImmutable
    {
        try {
            $modified = $entry->lastModified();
        } catch (\Throwable) {
            return null;
        }

        return $modified ? Carbon::parse($modified)->toDateTimeImmutable() : null;
    }
}
