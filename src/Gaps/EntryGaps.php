<?php

namespace NineteenNinetyFour\Ghostwriter\Gaps;

use Illuminate\Support\Facades\Cache;
use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraSources;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapReport;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PublishReadiness;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Readiness;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
use NineteenNinetyFour\Ghostwriter\Core\Layout\FillRates;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use NineteenNinetyFour\Ghostwriter\Drafts\BardDialect;
use NineteenNinetyFour\Ghostwriter\Drafts\FormBaseline;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Seo\HeadingProfiles;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use NineteenNinetyFour\Ghostwriter\Storage\FileRenderProfiles;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Fields\Blueprint;
use Throwable;

/**
 * "Finish this page" on a Statamic site: core's GapFinder over an entry's
 * values (saved, or as its publish form holds them), with Statamic's
 * dialects and ports, and the report in the editor's words.
 *
 * Nothing here calls a model or saves anything.
 */
class EntryGaps
{
    /** How long a collection's fill rates are kept at most; saving an entry in it forgets them sooner (forgetRates()). */
    private const PATTERN_SECONDS = 86400;

    private const RATES_KEY = 'ghostwriter.gaps.filled.';

    public function __construct(
        private SchemaReader $reader,
        private EntryLayouts $layouts,
        private BardDialect $bard,
        private FormBaseline $baseline,
        private StockImages $stock,
        private StockLibraries $libraries,
        private Settings $settings,
    ) {}

    /**
     * What is unfinished in an entry's data, in core's terms.
     *
     * @param  array<string, mixed>  $data  As the entry stores it.
     */
    /**
     * @param  list<string>|null  $sources  What a count to check may have been counted from (ExtraSources::fromSession()->all()): with them, a count whose list has changed since says so.
     */
    public function context(Blueprint $blueprint, array $data, ?string $collection = null, ?string $id = null, ?SessionGaps $session = null, ?string $site = null, ?array $sources = null): GapContext
    {
        $schema = $this->reader->schemaWithoutFolders($blueprint);

        return new GapContext(
            schema: $schema,
            entry: new EntryData(values: $data, id: $id, group: $collection, site: $site),
            richText: $this->bard,
            links: new StatamicLinks,
            placeholders: new StatamicPlaceholderAssets,
            assets: new StatamicAssetRefs,
            targets: new StatamicLinkTargets($site),
            stock: $this->stock,
            pattern: $collection !== null ? $this->pattern($schema, $collection, $blueprint->handle()) : null,
            session: $session ?? new SessionGaps,
            sources: $sources ?? [],
            group: $collection !== null ? HeadingProfiles::label($collection) : '',
            profile: $collection !== null ? $this->profile($collection, $blueprint->handle(), $site) : null,
        );
    }

    /**
     * Every gap in a context, for nothing: no detector that would ask a
     * model runs.
     */
    public function find(GapContext $context): GapReport
    {
        return GapFinder::standard()->find($context);
    }

    /**
     * The gaps in a publish form's values: the entry being edited (its
     * saved data under what the form holds), or a new one.
     *
     * @param  array<string, mixed>  $values  As the publish form holds them.
     */
    public function forForm(Blueprint $blueprint, array $values, ?Entry $entry, string $collection, ?Session $session = null, ?string $site = null): GapReport
    {
        $data = $this->data($blueprint, $values, $entry);
        $session ??= $entry ? $this->sessionOf($entry) : null;

        return GapFinder::standard()->find($this->context($blueprint, $data, $collection, $entry?->id(), $session ? SessionGaps::fromSession($session) : null, $site ?? $entry?->locale(), self::sources($session)));
    }

    /**
     * What a session's counts to check were counted from, as they stand
     * now: the person's messages and answers, and the draft.
     *
     * @return list<string>|null
     */
    public static function sources(?Session $session): ?array
    {
        return $session ? ExtraSources::fromSession($session)->all() : null;
    }

    /**
     * A publish form's values as the entry would store them: over the
     * entry's saved data when it has some.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function data(Blueprint $blueprint, array $values, ?Entry $entry = null): array
    {
        $data = $entry ? $this->baseline->data($entry, $values) : $this->baseline->values($blueprint, $values);

        // The date and slug live on the entry, not in its data, so the
        // baseline leaves them out; a required date filled in on the form
        // isn't empty.
        foreach (['date', 'slug'] as $handle) {
            $field = $blueprint->field($handle);

            if ($field && ! empty($values[$handle])) {
                // Only whether it's filled matters here: the form's own shape will do.
                $data[$handle] = $values[$handle];
            } elseif ($field && $entry && $handle === 'date' && $entry->hasDate()) {
                $data[$handle] = (string) $entry->date();
            }
        }

        return $data;
    }

    /**
     * The publish guard's verdict on an entry being saved.
     */
    public function readiness(Entry $entry): Readiness
    {
        $blueprint = $entry->blueprint();
        $data = $entry->values()->all();

        return PublishReadiness::standard($this->settings->onUnfinishedPublish())
            ->check($this->context($blueprint, $data, null, $entry->id() ? (string) $entry->id() : null, site: $entry->locale(), sources: $entry->id() ? self::sources($this->sessionOf($entry)) : null));
    }

    /**
     * The session gap list kept with the conversation this entry was
     * written or last edited in, where the person may see it.
     */
    public function sessionFor(Entry $entry): ?SessionGaps
    {
        $session = $this->sessionOf($entry);

        return $session ? SessionGaps::fromSession($session) : null;
    }

    /**
     * The conversation this entry was written or last edited in, where the
     * person may see it: its gap list, and what its counts were counted from.
     */
    public function sessionOf(Entry $entry): ?Session
    {
        try {
            $sessions = app(SessionGuard::class)->visible(Presenter::viewer());
        } catch (Throwable) {
            return null;
        }

        $id = (string) $entry->id();
        // A gap list, or links Ghostwriter added ("Check 3 links Ghostwriter added").
        $mine = array_filter($sessions, fn (Session $session) => ! SessionGaps::fromSession($session)->isEmpty() && ((string) $session->recordId === $id || (string) $session->source === $id));

        usort($mine, fn (Session $a, Session $b) => strcmp((string) $b->appliedAt, (string) $a->appliedAt));

        return $mine === [] ? null : $mine[0];
    }

    /**
     * A session's gap list, when the person may see it.
     */
    public function sessionById(?string $id): ?Session
    {
        if ($id === null || $id === '') {
            return null;
        }

        try {
            return app(SessionGuard::class)->find($id, Presenter::viewer());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The report for the guide, in the editor's words: each gap with its
     * message, speech label and fixes translated, and the tab its field
     * is on.
     *
     * @return array{count: int, blocking: int, prompting: int, suggestions: int, gaps: list<array<string, mixed>>}
     */
    public function present(GapReport $report, Blueprint $blueprint): array
    {
        $tabs = $this->tabs($blueprint);
        $array = $report->toArray();

        $array['gaps'] = array_map(function (Gap $gap) use ($tabs) {
            $out = $gap->toArray();
            $out['label'] = self::place($gap->label);
            $out['message'] = $this->text($gap->message());
            $out['speech'] = $this->text(new Message($gap->kind->speech()));
            // The name in a label ("Link to :title"), so the guide can cut just that short.
            $out['fixes'] = array_map(fn (Fix $fix) => ['label' => $this->text($fix->label), 'name' => isset($fix->label->params['title']) ? (string) $fix->label->params['title'] : null] + $fix->toArray(), $gap->fixes);
            $out['tab'] = $tabs[$gap->path->handle()] ?? null;
            // Facts already say only the editor knows them; a link left for a
            // person is the other place the reason helps.
            $out['reason'] = ($gap->meta['reason'] ?? null) === SessionGaps::FROM_DRAFT && in_array($gap->kind, [GapKind::LinkToChoose, GapKind::LinkEmpty], true) ? $this->text(new Message('gaps.guide.reason.draft')) : null;

            if (is_string($gap->meta['library'] ?? null)) {
                $out['meta']['libraryLabel'] = $this->libraries->shortLabel($gap->meta['library']);
            }

            return $out;
        }, $report->all());

        return $array;
    }

    /**
     * A core message in the editor's language: core's English source
     * string is the key for `__()`, as everywhere else in the addon, and a
     * stock library's ID becomes its name.
     */
    public function text(Message $message): string
    {
        $strings = Message::strings();
        $name = str_starts_with($message->key, 'gaps.') ? substr($message->key, 5) : $message->key;
        $params = $message->params;

        if (is_string($params['label'] ?? null)) {
            $params['label'] = self::place($params['label']);
        }

        if (is_string($params['library'] ?? null)) {
            $params['library'] = $this->libraries->shortLabel($params['library']);
        }

        if (isset($strings[$name])) {
            return __($strings[$name], array_map(fn ($value) => (string) $value, $params));
        }

        return (new Message($message->key, $params))->english();
    }

    /**
     * A place named once: "Text: Text" (a set and its field both called
     * Text) is "Text", and "Hero: Hero: Image" is "Hero: Image".
     */
    public static function place(string $label): string
    {
        $parts = [];

        foreach (explode(': ', $label) as $part) {
            if ($parts === [] || mb_strtolower(trim(end($parts))) !== mb_strtolower(trim($part))) {
                $parts[] = $part;
            }
        }

        return implode(': ', $parts);
    }

    /**
     * Each top-level field's tab, so the guide can open it.
     *
     * @return array<string, string>
     */
    public function tabs(Blueprint $blueprint): array
    {
        $tabs = [];

        foreach ($blueprint->tabs() as $tab) {
            foreach ($tab->fields()->all() as $field) {
                $tabs[$field->handle()] = (string) $tab->handle();
            }
        }

        return $tabs;
    }

    /**
     * How often a collection's newest published entries of this blueprint
     * fill each place, and how many there were (core's FillRates, over at
     * most 20): kept until an entry in the collection is saved, as the
     * guide checks as people type.
     */
    private function pattern(Schema $schema, string $collection, ?string $blueprint): ?Pattern
    {
        try {
            $rates = Cache::remember(self::RATES_KEY.$collection.'.'.$blueprint, self::PATTERN_SECONDS, function () use ($schema, $collection, $blueprint): array {
                $pattern = FillRates::pattern($schema, $this->layouts->newest($collection, $blueprint, FillRates::SIBLINGS));

                return ['entries' => $pattern->entries, 'filled' => $pattern->filled];
            });
        } catch (Throwable) {
            return null;
        }

        return is_array($rates) && is_array($rates['filled'] ?? null) ? new Pattern(entries: (int) ($rates['entries'] ?? 0), filled: $rates['filled']) : null;
    }

    /**
     * After an entry is saved: its collection's fill rates are counted
     * again on the next check.
     */
    public static function forgetRates(Entry $entry): void
    {
        $collection = (string) $entry->collectionHandle();
        $blueprints = Collection::findByHandle($collection)?->entryBlueprints()->map(fn (Blueprint $blueprint) => $blueprint->handle())->all() ?? [];

        foreach ([...$blueprints, ''] as $blueprint) {
            Cache::forget(self::RATES_KEY.$collection.'.'.$blueprint);
        }
    }

    /**
     * The collection's render profile for this blueprint, when a preview
     * has shown one: the set the template prints the `h1` from is the hero.
     * Only what is stored; nothing is rendered or studied for it.
     */
    private function profile(string $collection, string $blueprint, ?string $site): ?RenderProfile
    {
        try {
            $profile = app(FileRenderProfiles::class)->for($collection, $blueprint, $site);
        } catch (Throwable) {
            return null;
        }

        return $profile !== null && $profile->rendered() ? $profile : null;
    }
}
