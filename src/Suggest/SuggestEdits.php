<?php

namespace NineteenNinetyFour\Ghostwriter\Suggest;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\Sentences;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\ModelInputGuard;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\BlockRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Images\Shrinker;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\AnchorScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckText;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReview;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewAccess;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviews;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Findings;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewInput;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SiteDigest;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Suggestion;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionState;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Stock\Ledger;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\User;
use Throwable;

/**
 * Suggest edits on a Statamic entry's publish form: the free findings at
 * once, a review (core's EditReviews) started when someone asks and run as
 * a queued job, and what the guide shows of it. Everything is read from
 * the form as it is now, unsaved typing included, and nothing here saves
 * the entry: accepted changes go into the form in the browser. The only
 * save is alt text, on the asset, after its own confirm (the controller).
 *
 * Every suggestion goes to the guide with its words translated and its
 * place in the form (its path as the publish form addresses it now), so
 * the browser builds no sentence and asks nothing to move between steps.
 */
class SuggestEdits
{
    public function __construct(
        private EntryChecks $checks,
        private EntryGaps $gaps,
        private EditReviews $reviews,
        private TypeRepository $types,
        private Studio $studio,
        private GuideStore $guides,
    ) {}

    /**
     * What the checks read: the form's values (as the entry would store
     * them), with the index, the decisions that keep things quiet, and the
     * weekly link check's results.
     *
     * @param  array<string, mixed>|null  $data  As stored; the saved entry's when null.
     */
    public function context(Entry $entry, ?array $data = null, ?DateTimeImmutable $now = null): CheckContext
    {
        $now ??= Carbon::now()->toDateTimeImmutable();
        $ref = EntryChecks::ref($entry);

        return $this->checks->context(
            $entry,
            $data,
            $now,
            index: app(EntryIndex::class),
            quieted: $this->reviews->quieted($ref, $now),
            external: app(RevisitStore::class)->get($ref)?->external ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $values  As the publish form holds them.
     * @return array<string, mixed>
     */
    public function data(Entry $entry, array $values): array
    {
        return $values === [] ? $this->checks->saved($entry) : $this->checks->fromForm($entry, $values);
    }

    /**
     * The review call's input, from the entry as the form holds it (or as
     * saved), with the voice guide and the kind as the writer gets them.
     *
     * @param  array<string, mixed>  $data  As stored.
     */
    public function input(Entry $entry, array $data, ?DateTimeImmutable $now = null, ?string $replyLanguage = null): ReviewInput
    {
        $context = $this->context($entry, $data === [] ? null : $data, $now);
        $findings = Findings::standard()->find($context);

        return new ReviewInput(
            $context,
            $this->studio->writerContextFor($this->kind($entry), $this->voice()),
            $findings,
            SiteDigest::build($context, $findings),
            $this->images($findings),
            $replyLanguage ?? (string) config('app.locale', 'en'),
        );
    }

    /** How many calls a review of the page as it is now will make, for the confirm. */
    public function calls(Entry $entry, array $data): int
    {
        try {
            return $this->input($entry, $data)->calls();
        } catch (Throwable) {
            return 1;
        }
    }

    /**
     * What the guide shows: the latest review, re-checked against the
     * form (its stale suggestions marked), or, while one runs or before
     * any, the free findings. Never a model call.
     *
     * @param  array<string, mixed>  $values  As the publish form holds them.
     * @return array<string, mixed>
     */
    public function forGuide(Entry $entry, array $values): array
    {
        $data = $this->data($entry, $values);
        $context = $this->context($entry, $data);
        $ref = EntryChecks::ref($entry);
        $viewer = Presenter::viewer();
        $canEdit = (bool) User::current()?->can('edit', $entry);
        $access = EditReviewAccess::from(app(DomainOptions::class));
        $preview = $this->reviews->preview($context, $ref);
        $latest = is_array($preview['review']) ? EditReview::fromArray($preview['review']) : null;

        if ($latest !== null && ! $access->canSee($latest, $viewer, $canEdit)) {
            $latest = null;
        }

        $running = $latest !== null && $latest->status->isRunning();
        $stale = $latest !== null && $latest->startedBy !== null && $running && $latest->createdAt !== null && new DateTimeImmutable($latest->createdAt) < $context->now->modify('-'.EditReviews::STALE_RUN.' seconds');
        // Free findings are only candidates: nothing reaches the editor
        // until the review call has judged it in its paragraph. So the
        // guide shows a finished review's suggestions, and otherwise just
        // how many candidates there are.
        $source = $latest !== null && ! $running && ! $stale ? array_map(fn (Suggestion $suggestion) => $suggestion->toArray(), $latest->all()) : [];
        $tabs = $this->gaps->tabs($entry->blueprint());
        $suggestions = [];

        foreach ($source as $array) {
            $shown = $this->display($array, $context, $values, $tabs, $latest);

            if ($shown !== null) {
                $suggestions[] = $shown;
            }
        }

        return [
            'configured' => $this->studio->configured(),
            'calls' => $this->calls($entry, $data),
            'candidates' => count($preview['findings']),
            'review' => $latest === null ? null : [
                'id' => $latest->id,
                'status' => $stale ? 'failed' : $latest->status->value,
                'error' => $stale ? __('The review stopped before it finished.') : $this->error($latest->error),
                'by' => $viewer->is($latest->startedBy) ? null : Presenter::name($latest->startedBy !== null ? (string) $latest->startedBy : null),
                'mine' => $viewer->is($latest->startedBy),
                'ago' => $latest->finishedAt !== null ? Carbon::parse($latest->finishedAt)->diffForHumans() : null,
                'fresh' => $latest->contentHash === EditReviews::contentHash($context),
                'calls' => $latest->calls,
                'truncated' => $latest->truncated,
                'tokens' => (int) ($latest->usage['input'] ?? 0) + (int) ($latest->usage['output'] ?? 0),
                'canDecide' => $access->canDecide($latest, $viewer, $canEdit),
                'version' => $latest->version,
            ],
            'running' => $running && ! $stale,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * One suggestion as the guide shows it, or null when the form no
     * longer has its place.
     *
     * @param  array<string, mixed>  $array  Suggestion::toArray()
     * @param  array<string, mixed>  $values  The form's values.
     * @param  array<string, string>  $tabs
     * @return array<string, mixed>|null
     */
    public function display(array $array, CheckContext $context, array $values, array $tabs, ?EditReview $review = null): ?array
    {
        $suggestion = Suggestion::fromArray($array);
        $anchor = $suggestion->anchor;

        if ($suggestion->state === SuggestionState::Expired) {
            return null;
        }

        $text = $context->textAt($anchor->path->toString());
        $field = $text?->visit->field;
        $finding = null;

        foreach ($review?->findings ?? [] as $stored) {
            if (($stored['id'] ?? null) === $suggestion->finding) {
                $finding = Finding::fromArray($stored);
            }
        }

        $meta = $finding?->meta ?? [];
        $dotted = self::formPath($anchor->path, $values !== [] ? $values : $context->gaps->entry->values);
        $reason = $suggestion->reason;
        $label = $reason->text !== '' ? $reason->text : ($reason->message !== null ? $this->text($reason->message) : '');
        $seo = null;

        if ($suggestion->category->value === 'seo' || str_starts_with((string) ($finding?->kind ?? ''), 'seo-')) {
            $seo = $this->seoOf($anchor->path, $context);
        }

        $decision = $review?->lastDecision($suggestion->id);

        return [
            'id' => $suggestion->id,
            'category' => $suggestion->category->value,
            'label' => $this->text(new Message($suggestion->category->label())),
            'speech' => $this->text(new Message($suggestion->category->speech())),
            'wording' => $suggestion->category->isWording(),
            'state' => $suggestion->state->value,
            'scope' => $anchor->scope->value,
            'path' => $anchor->path->toString(),
            'dotted' => $dotted,
            'handle' => $anchor->path->handle(),
            'tab' => $tabs[$anchor->path->handle()] ?? null,
            'place' => EntryGaps::place($anchor->label),
            'fieldType' => $field?->type ?? ($seo !== null ? 'seo' : null),
            'quote' => $anchor->quote?->toArray(),
            'occurrence' => $anchor->occurrence,
            'reason' => $label,
            'source' => $this->text($reason->sourceLabel()),
            'free' => $suggestion->free,
            'finding' => $suggestion->finding,
            'replacement' => $suggestion->replacement,
            'alternatives' => $suggestion->alternatives,
            'versions' => $review?->versions[$suggestion->id] ?? [],
            'fact' => $suggestion->fact === null ? null : [
                'ask' => $suggestion->fact->ask !== '' ? $suggestion->fact->ask : $this->text(new Message('suggest.fact.ask')),
                'template' => $suggestion->fact->template,
                'without' => $suggestion->fact->without,
                'answer' => $suggestion->fact->answer->value,
            ],
            'link' => $suggestion->link === null ? null : $this->link($suggestion->link->toArray()),
            'asset' => $anchor->asset === null ? null : $this->asset($anchor->asset->volume, $anchor->asset->path, $context),
            'seo' => $seo,
            'free_label' => $suggestion->replacement === null && $suggestion->fact === null && $suggestion->link === null ? $this->text(new Message($anchor->scope === AnchorScope::Asset ? 'suggest.free.alt' : 'suggest.free.rewrite')) : null,
            'decided' => $decision === null || $decision->by === null || $suggestion->state->isOpen() ? null : [
                'state' => $decision->state->value,
                'by' => Presenter::viewer()->is($decision->by) ? null : Presenter::name((string) $decision->by),
            ],
            'phrase' => is_string($meta['phrase'] ?? null) ? $meta['phrase'] : null,
            'context' => $anchor->scope === AnchorScope::Range && $anchor->quote !== null && $text !== null ? self::around($text, $anchor->quote, $anchor->occurrence) : null,
            'meta' => array_intersect_key($meta, array_flip(['limit', 'length', 'title', 'url', 'share'])),
        ];
    }

    /**
     * A core message in the editor's language: core's English source
     * string is the key for `__()`, as everywhere else in the addon.
     */
    public function text(Message $message): string
    {
        $dot = strpos($message->key, '.');
        $namespace = $dot !== false && in_array(substr($message->key, 0, $dot), ['suggest', 'revisit', 'gaps'], true) ? substr($message->key, 0, $dot) : 'gaps';
        $name = $namespace === 'gaps' && ! str_starts_with($message->key, 'gaps.') ? $message->key : substr($message->key, strlen($namespace) + 1);
        $strings = Message::strings($namespace);
        $params = array_map(fn ($value) => is_string($value) ? EntryGaps::place($value) : (string) $value, $message->params);

        return isset($strings[$name]) ? __($strings[$name], $params) : $message->english();
    }

    /**
     * The path the publish form addresses a value by now: blocks and rows
     * found by their IDs in the form's values, so a set moved since the
     * review still finds its field. A Bard set's fields sit in
     * `<index>.attrs.values`.
     *
     * @param  array<string, mixed>  $values
     */
    public static function formPath(FieldPath $path, array $values): string
    {
        $out = [];
        $current = $values;

        foreach ($path->segments as $segment) {
            if (is_string($current) && str_starts_with(ltrim($current), '[')) {
                $decoded = json_decode($current, true);
                $current = is_array($decoded) ? $decoded : $current;
            }

            if (! $segment instanceof BlockRef) {
                $out[] = $segment;
                $current = is_array($current) ? ($current[$segment] ?? null) : null;

                continue;
            }

            $list = is_array($current) ? array_values($current) : [];
            $index = $segment->index;

            if ($segment->id !== null && $segment->id !== '') {
                foreach ($list as $i => $item) {
                    $id = is_array($item) ? ($item['id'] ?? $item['_id'] ?? $item['attrs']['id'] ?? null) : null;

                    if ($id !== null && (string) $id === (string) $segment->id) {
                        $index = $i;

                        break;
                    }
                }
            }

            $item = $list[$index] ?? null;
            $out[] = (string) $index;

            if (is_array($item) && ($item['type'] ?? null) === 'set' && is_array($item['attrs'] ?? null)) {
                array_push($out, 'attrs', 'values');
                $current = $item['attrs']['values'] ?? null;
            } else {
                $current = $item;
            }
        }

        return implode('.', $out);
    }

    /**
     * The rest of the sentence (or sentences) a quote sits in, either side
     * of it, so the guide shows the change in its sentence and the editor
     * can judge the fit.
     *
     * @return array{before: string, after: string}|null
     */
    public static function around(CheckText $text, TextQuote $quote, int $occurrence = 0): ?array
    {
        $plain = $text->plain;
        $match = (new QuoteFinder)->find($quote, $plain, $occurrence);

        if ($match === null) {
            return null;
        }

        $block = $text->blockAt($match->offset);
        [$blockStart, $blockLength] = $block !== null ? $text->blocks[$block] : [0, mb_strlen($plain)];
        $line = mb_substr($plain, $blockStart, $blockLength);
        [$at, $size] = Sentences::covering($line, $match->offset - $blockStart, $match->length);
        $start = $blockStart + $at;
        $end = $start + $size;

        return [
            'before' => mb_substr($plain, $start, max(0, $match->offset - $start)),
            'after' => mb_substr($plain, $match->offset + $match->length, max(0, $end - $match->offset - $match->length)),
        ];
    }

    /**
     * How many of the site's pages use an asset, for "Save to the image"'s
     * confirm. Statamic keeps no index of it, so pages are read (kept a
     * minute).
     */
    public function uses(string $container, string $path): int
    {
        return (int) Cache::remember('ghostwriter.suggest.uses.'.sha1($container.'::'.$path), 60, function () use ($container, $path) {
            $count = 0;
            $needles = [$container.'::'.$path, '"'.$path.'"'];

            foreach ($this->types->collections() as $collection) {
                foreach (Entries::query()->where('collection', $collection->handle())->lazy(200) as $entry) {
                    $json = (string) json_encode($entry->data()->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    foreach ($needles as $needle) {
                        if (str_contains($json, $needle)) {
                            $count++;

                            break;
                        }
                    }
                }
            }

            return $count;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(string $container, string $path, CheckContext $context): array
    {
        $alt = $context->gaps->alt;

        return [
            'key' => $container.'::'.$path,
            'container' => $container,
            'path' => $path,
            'filename' => basename($path),
            'alt' => $alt?->altFor(AssetRef::statamic($container, $path)),
            'uses' => $this->uses($container, $path),
            'canEdit' => (bool) User::current()?->can("edit {$container} assets"),
            'url' => app(StatamicAssetAlt::class)->asset(AssetRef::statamic($container, $path))?->thumbnailUrl('small'),
        ];
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array<string, mixed>
     */
    private function link(array $link): array
    {
        $target = is_scalar($link['target'] ?? null) ? (string) $link['target'] : '';
        $target = (string) preg_replace('#^statamic://#', '', $target);

        return $link + ['value' => $target, 'href' => $target !== '' && ! preg_match('#^https?://#', $target) ? 'statamic://'.$target : $target];
    }

    /**
     * Where an SEO value is, for writing it: SEO Pro's field keeps each
     * value as `{source, value}` in the form.
     *
     * @return array<string, mixed>|null
     */
    private function seoOf(FieldPath $path, CheckContext $context): ?array
    {
        foreach ($context->gaps->seo?->in($context->gaps->schema, $context->gaps->entry) ?? [] as $field) {
            if ($field->path->toString() === $path->toString()) {
                return [
                    'pro' => count($path->segments) > 1,
                    'limit' => $field->limit,
                    'length' => $field->length(),
                    'text' => $field->text,
                    'inheritsFrom' => $field->inheritsFrom,
                    'source' => $field->source->value,
                    'writable' => $field->writable,
                ];
            }
        }

        return null;
    }

    /**
     * Thumbnails of images with no alt text, for the review call to
     * describe: at most four, never one whose licence forbids it (a
     * refused image's step stays "Describe it yourself").
     *
     * @param  list<Finding>  $findings
     * @return array<string, Image>
     */
    private function images(array $findings): array
    {
        $images = [];
        $guard = app(ModelInputGuard::class);
        $shrinker = new Shrinker;

        foreach ($findings as $finding) {
            if (count($images) >= ReviewInput::IMAGES_PER_CALL || $finding->kind !== 'missing-alt' || $finding->anchor->asset === null) {
                continue;
            }

            try {
                $asset = app(StatamicAssetAlt::class)->asset($finding->anchor->asset);
                $bytes = $asset ? (string) $asset->contents() : '';

                if ($bytes === '' || ! $guard->allowsImage($bytes, Ledger::ref($asset), $asset->basename())) {
                    continue;
                }

                if ($image = $shrinker->small($bytes)) {
                    $images[$finding->id] = $image;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $images;
    }

    private function kind(Entry $entry): ContentType
    {
        $collection = $entry->collection();

        return $this->types->forCollection((string) $entry->collectionHandle())->first() ?? TypeRepository::generic($collection);
    }

    private function voice(): string
    {
        try {
            return (string) $this->guides->guide(Guide::VOICE)->body;
        } catch (Throwable) {
            return '';
        }
    }

    private function error(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }

        if ($error !== 'unreadable') {
            return $error; // A provider's message, already in words.
        }

        // The code core sets, in core's words (here too for a core without them).
        $words = $this->text(new Message('suggest.review.error.unreadable'));

        return $words !== 'suggest.review.error.unreadable' ? $words : __('the answer came back in a shape I couldn\'t read. Try again.');
    }
}
