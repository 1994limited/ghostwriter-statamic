<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extra;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraItem;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Source;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\SourceKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanOrigin;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\SessionLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use NineteenNinetyFour\Ghostwriter\Seo\HeadingProfiles;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Layouts and extras on a Statamic session (core's Arrange\SessionLayouts):
 * the site's side of them, and what the panel shows.
 *
 * - The first draft's turn stores the writer's draft with its own layout
 *   straight away, then asks the layout planner for up to two more (one
 *   call) and stores those: the panel shows the draft meanwhile, with
 *   "Finding other layouts…". Later turns re-arrange without a call.
 * - Any other change to the draft (click-to-edit, Edit YAML) re-arranges
 *   the layouts, no call; one that no longer fits is "Needs refreshing".
 * - The chosen layout is stored on the session, so it is everyone's on the
 *   piece; "Use this draft" and the preview build from it.
 *
 * Layouts never fail a turn or an edit: anything that goes wrong here is
 * logged and the piece carries on with the writer's layout.
 */
class DraftLayouts
{
    /** How long a planner call is shown as under way, at most. */
    public const PLANNING_SECONDS = 900;

    public function __construct(
        private SessionLayouts $layouts,
        private SchemaReader $reader,
        private EntryLayouts $entries,
        private SessionGuard $sessions,
        private SessionStore $store,
        private HeadingProfiles $headings,
    ) {}

    /**
     * Where a piece is going, as layouts need it: the blueprint's schema,
     * and (with $site) the collection's pattern, the entries it was found
     * from, the kind's defaults and the examples the writer was shown.
     * Null when the collection or blueprint has gone.
     */
    public function context(ContentType $type, ?Blueprint $blueprint = null, bool $site = true): ?LayoutContext
    {
        $blueprint ??= TypeRepository::blueprintOf($type);

        if (! $blueprint) {
            return null;
        }

        $schema = $this->reader->schema($blueprint);

        if (! $site) {
            return new LayoutContext($schema, profile: $this->headings->for($type, $blueprint, $schema));
        }

        $studied = $this->entries->studied($type->group, $type->variant, $type->where, $type->examples);
        $pattern = $this->entries->patternOf($schema, $studied);

        // The writer is shown the two newest it was modelled on (PatternFinder).
        $examples = array_map(fn ($entry) => $entry->id, array_slice($studied, 0, 2));

        return new LayoutContext($schema, $pattern, $studied, $type->defaults, $examples, $this->headings->for($type, $blueprint, $schema, $studied));
    }

    /**
     * context(), or null (logged) when the site can't be read: a draft is
     * never lost over its layouts.
     */
    public function safeContext(ContentType $type, ?Blueprint $blueprint = null): ?LayoutContext
    {
        try {
            return $this->context($type, $blueprint);
        } catch (Throwable $exception) {
            $this->log($exception);

            return null;
        }
    }

    /**
     * After the writer's turn, on the session as it is saved. A later turn
     * re-arranges the layouts from the writer's reply (no call). The first
     * draft gets its own layout now; plan() then asks for the others.
     */
    public function afterTurn(Session $session, ?string $before, TaggedResponse $response, Conversation $conversation, WriterContext $writer, LayoutContext $site): void
    {
        $this->quietly(fn () => self::isFirst($before)
            ? $this->layouts->afterEdit($session, null, $site)
            : $this->layouts->afterWriter($session, $before, $response, $conversation, $writer, $site));
    }

    /**
     * The first draft's other layouts: the writer's extras read, and one
     * call to the layout planner, outside the session's lock (the panel
     * shows the draft meanwhile). What it finds goes onto the session as it
     * is by then; a draft edited meanwhile re-arranges them.
     */
    public function plan(string $sessionId, TaggedResponse $response, Conversation $conversation, WriterContext $writer, LayoutContext $site): void
    {
        try {
            $copy = $this->store->find($sessionId);

            if (! $copy || $copy->draft === null) {
                return;
            }

            $usage = $this->layouts->afterWriter($copy, null, $response, $conversation, $writer, $site);

            $this->sessions->change($sessionId, function (Session $latest) use ($copy, $usage, $site) {
                $latest->extras = $copy->extras;
                $this->settle($latest, $copy, $usage, $site);
            });
        } catch (Throwable $exception) {
            $this->log($exception);
        } finally {
            self::planned($sessionId);
        }
    }

    /**
     * "Refresh layouts": one call to the planner for new alternatives to
     * the draft as it is now. The writer's layout stays first.
     */
    public function refresh(string $sessionId, LayoutContext $site): void
    {
        try {
            $copy = $this->store->find($sessionId);

            if (! $copy || $copy->draft === null) {
                return;
            }

            $usage = $this->layouts->refresh($copy, $site);

            $this->sessions->change($sessionId, function (Session $latest) use ($copy, $usage, $site) {
                $extras = $latest->extras;
                $this->settle($latest, $copy, $usage, $site);

                // Extras changed meanwhile are the editor's: kept, and the
                // new layouts re-arranged around them.
                if ($extras !== $copy->extras) {
                    $latest->extras = $extras;
                    $this->quietly(fn () => $this->layouts->afterEdit($latest, $latest->draft, $site));
                }
            });
        } catch (Throwable $exception) {
            $this->log($exception);
        } finally {
            self::planned($sessionId);
        }
    }

    /**
     * After the draft changed by hand (click-to-edit, Edit YAML): the
     * layouts re-arranged to the new text. No call.
     */
    public function afterEdit(Session $session, ?string $before, ?ContentType $type): void
    {
        if (! $type || $session->plans === []) {
            return;
        }

        $this->quietly(function () use ($session, $before, $type) {
            if ($site = $this->safeContext($type->forSession($session))) {
                $this->layouts->afterEdit($session, $before, $site);
            }
        });
    }

    /**
     * Choose a layout for everyone on the piece.
     *
     * @throws InvalidArgumentException for one the piece doesn't have, or one that needs refreshing.
     */
    public function choose(Session $session, string $planId): void
    {
        $this->layouts->choose($session, $planId);
    }

    /**
     * @param  array<string, string>|null  $parts
     */
    public function editExtra(Session $session, string $itemId, string $text, ?array $parts, ContentType $type): void
    {
        $this->ensureItem($session, $itemId);
        $this->layouts->editExtra($session, $itemId, $text, $parts, $this->context($type->forSession($session)));
    }

    public function deleteExtra(Session $session, string $itemId, ContentType $type): void
    {
        $this->ensureItem($session, $itemId);

        $site = $this->context($type->forSession($session));

        if ($site === null) {
            throw new InvalidArgumentException('The collection this was written for no longer exists.');
        }

        $this->layouts->deleteExtra($session, $itemId, $site);
    }

    /**
     * The chosen layout's draft, for "Use this draft" and the preview: the
     * session's draft itself when the writer's layout is chosen, else the
     * same words arranged by the chosen plan. A plan that can't be
     * arranged into this blueprint falls back to the writer's draft.
     */
    public function draft(Session $session, ContentType $type, ?Blueprint $blueprint = null, ?string $planId = null): Draft
    {
        $draft = Draft::parse((string) $session->draft);
        $chosen = $planId === null || $session->plans === [] ? $this->chosen($session) : $this->layouts->plans($session)->get($planId);

        try {
            $site = $this->context($type->forSession($session), $blueprint, site: false);

            if ($site === null) {
                return $draft;
            }

            // The writer's own layout too: the SEO pass fits its headings to
            // the profile as it is now, which a render may have just changed.
            $data = $this->layouts->draftData($session, $site, $chosen?->id);

            return $data === $draft->data ? $draft : new Draft($data, trim(Yaml::dump($data, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)));
        } catch (Throwable $exception) {
            $this->log($exception);

            return $draft;
        }
    }

    /**
     * The chosen layout, or null when the piece has none yet.
     */
    public function chosen(Session $session): ?Plan
    {
        try {
            return $session->plans === [] ? null : $this->layouts->chosen($session);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The layout cards and the extras, as the panel shows them.
     *
     * @return array{layouts: array<string, mixed>, extras: list<array<string, mixed>>}
     */
    public function present(Session $session, ?ContentType $type = null): array
    {
        $plans = $session->plans === [] ? [] : $this->layouts->plans($session)->all();
        $chosen = $this->chosen($session);
        $used = $chosen?->extrasUsed() ?? [];
        $site = $type && count($plans) > 1 ? $this->quietContext($type->forSession($session)) : null;
        // What each layout changes against the writer's: words for its chip, and where to point on a switch.
        $changes = $site ? $this->layouts->changes($session, $site->schema, $site->profile) : [];

        return [
            'layouts' => [
                // The planner is still looking for other layouts.
                'planning' => self::isPlanning($session->id),
                'chosen' => $chosen?->id,
                'chosen_name' => $chosen && count($plans) > 1 ? $chosen->name : null,
                'stale' => collect($plans)->contains(fn (Plan $plan) => $plan->stale),
                'plans' => array_map(fn (Plan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'blocks' => $plan->blockCount(),
                    'suggested' => $plan->suggested,
                    'stale' => $plan->stale,
                    'writer' => $plan->origin === PlanOrigin::Writer,
                    // Its blocks by name, in order: the card's outline where
                    // there is no thumbnail, and what a screen reader hears.
                    'outline' => self::outline($plan, $site?->schema),
                    // "Closing line as a quote": one to three, against the writer's.
                    'changes' => $changes[$plan->id]['summary'] ?? [],
                    // Where it changed: {field, block, section}, as the preview's map counts them.
                    'places' => $changes[$plan->id]['places'] ?? [],
                ], $plans),
            ],
            'extras' => $this->presentExtras($session, $used, $chosen),
        ];
    }

    /**
     * @return list<string>
     */
    private static function outline(Plan $plan, ?Schema $schema): array
    {
        $names = [];

        foreach ($plan->sequences() as $handle => $types) {
            $field = $schema?->field($handle);

            foreach ($types as $type) {
                $label = $field?->set($type)?->label;
                $names[] = $label !== null && $label !== '' ? $label : match (true) {
                    preg_match('/\\Ah([1-6])\\z/', $type, $m) === 1 => __('Heading :n', ['n' => $m[1]]),
                    $type === 'text', $type === 'p' => __('Text'),
                    $type === 'list' => __('List'),
                    $type === 'quote' => __('Quote'),
                    $type === 'value' => $field?->label ?: $handle,
                    default => ucfirst(str_replace(['set:', '_'], ['', ' '], $type)),
                };
            }
        }

        return $names;
    }

    private function quietContext(ContentType $type): ?LayoutContext
    {
        try {
            return $this->context($type, site: false);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The draft's Blocks and Text views follow the chosen layout. Writing is
     * still edited in the draft itself, so a piece of writing can be changed
     * in place only where it is one value of the draft as it stands; the
     * rest (moved, joined, an extra) is changed in its own place.
     *
     * @param  array<int, array<string, mixed>>  $nodes  Core's DraftPreview of the arranged data.
     * @param  array<string, mixed>  $arranged
     * @param  array<string, mixed>  $draft  The session's own draft data.
     * @return array<int, array<string, mixed>>
     */
    public static function editablePaths(array $nodes, array $arranged, array $draft): array
    {
        $index = [];
        self::index($draft, [], $index);

        return self::remap($nodes, $arranged, $index);
    }

    /**
     * @param  array<int, string>  $used
     * @return list<array<string, mixed>>
     */
    private function presentExtras(Session $session, array $used, ?Plan $chosen): array
    {
        if ($session->extras === []) {
            return [];
        }

        $text = app(EntryGaps::class);
        $name = $chosen?->name;

        return array_map(function (Extra $extra) use ($used, $text, $name) {
            $items = array_map(fn (ExtraItem $item) => [
                'id' => $item->id,
                // Markers and all: the extras list shows asks and counts to check as chips.
                'text' => $item->text,
                'parts' => $item->parts,
                'source' => $this->source($item->source),
                'state' => $item->needsReview() ? 'needs-review' : ($item->needsAnswer() ? 'needs-answer' : null),
                'state_label' => ($state = $item->state()) ? $text->text($state) : null,
                'count_label' => ($label = $item->countLabel()) ? $text->text($label) : null,
                'used' => in_array($item->id, $used, true),
                // As stored, markers and all: what an edit sends back for the parts it didn't touch.
                'raw' => ['text' => $item->text, 'parts' => (object) $item->parts],
            ], $extra->items);

            $inUse = collect($items)->contains(fn (array $item) => $item['used']);

            return [
                'id' => $extra->id,
                'kind' => $extra->kind->value,
                'label' => self::kindLabel($extra->kind),
                'parts' => $extra->kind->parts(),
                'used' => $inUse,
                'use_label' => $name === null ? null : ($inUse ? __('Used in :name', ['name' => $name]) : __('Not used in :name', ['name' => $name])),
                'items' => $items,
            ];
        }, $this->layouts->extras($session)->all());
    }

    /**
     * Where an item came from, in the editor's words: "from your answer",
     * "from the draft", "from About us" (with its edit link).
     *
     * @return array{kind: string, label: string, url: ?string}|null
     */
    private function source(?Source $source): ?array
    {
        if ($source === null) {
            return null;
        }

        $url = null;
        $label = match ($source->kind) {
            SourceKind::Brief => __('from your brief'),
            SourceKind::Answer => $source->ref !== null && ctype_digit($source->ref) ? __('from your answer · question :n', ['n' => $source->ref]) : __('from your answer'),
            SourceKind::Draft => __('from the draft'),
            SourceKind::Conversation => __('from your message'),
            SourceKind::Editor => __('your words'),
            SourceKind::Entry => __('from :title', ['title' => $this->entryTitle($source, $url)]),
        };

        return ['kind' => $source->kind->value, 'label' => $label, 'url' => $url];
    }

    private function entryTitle(Source $source, ?string &$url): string
    {
        $entry = $source->entryId !== null ? Entry::find((string) $source->entryId) : null;
        $url = $entry?->editUrl();

        return (string) ($entry?->get('title') ?? $source->entryTitle ?? __('an existing page'));
    }

    private static function kindLabel(ExtraKind $kind): string
    {
        return match ($kind) {
            ExtraKind::Stats => __('Stats'),
            ExtraKind::Faq => __('FAQs'),
            ExtraKind::PullQuote => __('Pull quote'),
            ExtraKind::AtAGlance => __('At a glance'),
            ExtraKind::Caption => __('Caption'),
            ExtraKind::Cta => __('Call to action'),
            ExtraKind::Testimonial => __('Testimonial'),
            ExtraKind::Intro => __('Intro'),
        };
    }

    /**
     * The layouts found on a copy of the session, onto the session as it is
     * now: a draft edited meanwhile re-arranges them to its text.
     */
    private function settle(Session $latest, Session $copy, Usage $usage, LayoutContext $site): void
    {
        $edited = $latest->draft !== $copy->draft;

        $latest->units = $copy->units;
        $latest->plans = $copy->plans;

        if ($latest->plan !== null && collect($copy->plans)->doesntContain(fn (array $plan) => ($plan['id'] ?? null) === $latest->plan)) {
            $latest->plan = null;
        }

        $latest->usage = ['input' => (int) ($latest->usage['input'] ?? 0) + $usage->input, 'output' => (int) ($latest->usage['output'] ?? 0) + $usage->output] + $latest->usage;

        if ($edited) {
            $this->quietly(fn () => $this->layouts->afterEdit($latest, $copy->draft, $site));
        }
    }

    private function ensureItem(Session $session, string $itemId): void
    {
        if ($this->layouts->extras($session)->item($itemId) === null) {
            throw new InvalidArgumentException('That extra is not on this piece any more.');
        }
    }

    // -- The planner, under way ----------------------------------------------

    /** Marked when a planner call is queued, until it has finished. */
    public static function planning(string $sessionId): void
    {
        Cache::put(self::planningKey($sessionId), true, self::PLANNING_SECONDS);
    }

    public static function planned(string $sessionId): void
    {
        Cache::forget(self::planningKey($sessionId));
    }

    public static function isPlanning(string $sessionId): bool
    {
        return (bool) Cache::get(self::planningKey($sessionId), false);
    }

    public static function isFirst(?string $before): bool
    {
        return $before === null || trim($before) === '';
    }

    private static function planningKey(string $sessionId): string
    {
        return 'ghostwriter.layouts.planning.'.$sessionId;
    }

    // -- Paths --------------------------------------------------------------

    /**
     * Every piece of writing in the draft by its value: where it is.
     *
     * @param  array<int, string|int>  $path
     * @param  array<string, list<array<int, string|int>>>  $index
     */
    private static function index(mixed $value, array $path, array &$index): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                self::index($child, [...$path, $key], $index);
            }

            return;
        }

        if (is_string($value) && trim($value) !== '') {
            $index[$value][] = $path;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $arranged
     * @param  array<string, list<array<int, string|int>>>  $index
     * @return array<int, array<string, mixed>>
     */
    private static function remap(array $nodes, array $arranged, array $index): array
    {
        return array_map(function (array $node) use ($arranged, $index) {
            if (($node['editable'] ?? false) === true) {
                $value = self::at($arranged, $node['path'] ?? []);
                $found = is_string($value) ? ($index[$value] ?? []) : [];

                if (count($found) === 1) {
                    $node['path'] = $found[0];
                } else {
                    $node['editable'] = false;
                }
            }

            // Read-only here (an extra, mostly): a count to check reads as
            // the page will say it; the extras list says it needs review.
            if (($node['editable'] ?? false) !== true) {
                foreach (['text', 'html'] as $key) {
                    if (is_string($node[$key] ?? null)) {
                        $node[$key] = Markers::withoutChecks($node[$key]);
                    }
                }
            }

            foreach (['fields'] as $key) {
                if (isset($node[$key]) && is_array($node[$key])) {
                    $node[$key] = self::remap($node[$key], $arranged, $index);
                }
            }

            if (($node['kind'] ?? null) === 'blocks') {
                $node['items'] = array_map(fn (array $block) => ['fields' => self::remap($block['fields'] ?? [], $arranged, $index)] + $block, $node['items'] ?? []);
            }

            if (($node['kind'] ?? null) === 'rows') {
                $node['items'] = array_map(fn (array $row) => self::remap($row, $arranged, $index), $node['items'] ?? []);
            }

            return $node;
        }, $nodes);
    }

    /**
     * @param  array<int, string|int>  $path
     */
    private static function at(array $data, array $path): mixed
    {
        $node = $data;

        foreach ($path as $step) {
            if (! is_array($node) || ! array_key_exists($step, $node)) {
                return null;
            }

            $node = $node[$step];
        }

        return $node;
    }

    private function quietly(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            $this->log($exception);
        }
    }

    private function log(Throwable $exception): void
    {
        Log::channel(config('ghostwriter.log_channel'))->warning('Ghostwriter: laying out the draft failed: '.$exception->getMessage());
    }
}
