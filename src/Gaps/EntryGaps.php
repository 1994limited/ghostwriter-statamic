<?php

namespace NineteenNinetyFour\Ghostwriter\Gaps;

use Illuminate\Support\Facades\Cache;
use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
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
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Drafts\BardDialect;
use NineteenNinetyFour\Ghostwriter\Drafts\FormBaseline;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use Statamic\Contracts\Entries\Entry;
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
    /** How long a collection's fill rates are kept between checks. */
    private const PATTERN_SECONDS = 600;

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
    public function context(Blueprint $blueprint, array $data, ?string $collection = null, ?string $id = null, ?SessionGaps $session = null, ?string $site = null): GapContext
    {
        $schema = $this->reader->schemaWithoutFolders($blueprint);

        return new GapContext(
            schema: $schema,
            entry: new EntryData(values: $data, id: $id),
            richText: $this->bard,
            links: new StatamicLinks,
            placeholders: new StatamicPlaceholderAssets,
            assets: new StatamicAssetRefs,
            targets: new StatamicLinkTargets($site),
            stock: $this->stock,
            pattern: $collection !== null ? $this->pattern($schema, $collection, $blueprint->handle()) : null,
            session: $session ?? new SessionGaps,
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
    public function forForm(Blueprint $blueprint, array $values, ?Entry $entry, string $collection, ?SessionGaps $session = null, ?string $site = null): GapReport
    {
        $data = $this->data($blueprint, $values, $entry);

        return GapFinder::standard()->find($this->context($blueprint, $data, $collection, $entry?->id(), $session ?? ($entry ? $this->sessionFor($entry) : null), $site ?? $entry?->locale()));
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
            ->check($this->context($blueprint, $data, null, $entry->id() ? (string) $entry->id() : null, site: $entry->locale()));
    }

    /**
     * The session gap list kept with the conversation this entry was
     * written or last edited in, where the person may see it.
     */
    public function sessionFor(Entry $entry): ?SessionGaps
    {
        try {
            $sessions = app(SessionGuard::class)->visible(Presenter::viewer());
        } catch (Throwable) {
            return null;
        }

        $id = (string) $entry->id();
        $mine = array_filter($sessions, fn (Session $session) => $session->gaps !== [] && ((string) $session->recordId === $id || (string) $session->source === $id));

        usort($mine, fn (Session $a, Session $b) => strcmp((string) $b->appliedAt, (string) $a->appliedAt));

        return $mine === [] ? null : SessionGaps::fromSession($mine[0]);
    }

    /**
     * A session's gap list, when the person may see it.
     */
    public function sessionById(?string $id): ?SessionGaps
    {
        if ($id === null || $id === '') {
            return null;
        }

        try {
            return SessionGaps::fromSession(app(SessionGuard::class)->find($id, Presenter::viewer()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The report for the guide, in the editor's words: each gap with its
     * message, speech label and fixes translated, and the tab its field
     * is on.
     *
     * @return array{count: int, blocking: int, suggestions: int, gaps: list<array<string, mixed>>}
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
            $out['fixes'] = array_map(fn (Fix $fix) => ['label' => $this->text($fix->label)] + $fix->toArray(), $gap->fixes);
            $out['tab'] = $tabs[$gap->path->handle()] ?? null;
            // Facts already say they weren't guessed; a link left for a
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
     * How often a collection's entries fill each place, kept a while: it
     * reads every published entry, and the guide checks as people type.
     */
    private function pattern(Schema $schema, string $collection, ?string $blueprint): ?Pattern
    {
        try {
            $filled = Cache::remember('ghostwriter.gaps.filled.'.$collection.'.'.$blueprint, self::PATTERN_SECONDS, fn () => $this->layouts->pattern($schema, $collection, $blueprint)->filled);
        } catch (Throwable) {
            return null;
        }

        return new Pattern(filled: is_array($filled) ? $filled : []);
    }
}
