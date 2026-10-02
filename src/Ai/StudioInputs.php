<?php

namespace NineteenNinetyFour\Ghostwriter\Ai;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Content\ProseExtractor;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ImagerySample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanContext;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanGroup;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanItem;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlannedIdea;
use NineteenNinetyFour\Ghostwriter\Core\Studio\TypeSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\VoiceSample;
use NineteenNinetyFour\Ghostwriter\Stock\Ledger;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Collection as EntryCollection;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry as Entries;
use Statamic\Fields\Blueprint;
use Statamic\Support\Str;

/**
 * Turns Statamic's objects (collections, blueprints, entries, types and
 * sessions) into the neutral inputs core's Studio takes. Which entries are
 * shown, and how they are read, stays here.
 */
class StudioInputs
{
    /** Entries looked at when working out the kinds a collection holds. */
    public const KIND_SAMPLE = 60;

    /** Entry titles the brief writer is shown. */
    public const BRIEF_TITLES = 40;

    public function __construct(
        private SchemaReader $reader,
        private EntryLayouts $layouts,
        private ProseExtractor $prose,
    ) {}

    /**
     * @param  Collection<int, array{title: string, collection: string, url: ?string, text: string}>  $samples
     * @return array<int, VoiceSample>
     */
    public function voiceSamples(Collection $samples): array
    {
        return $samples->map(fn (array $sample) => new VoiceSample($sample['title'], $sample['collection'], $sample['text']))->values()->all();
    }

    /**
     * @param  array<int, string>  $examples  Entry IDs chosen by an editor.
     */
    public function typeSurvey(EntryCollection $collection, Blueprint $blueprint, ?string $title, array $examples): TypeSurvey
    {
        $schema = $this->reader->schema($blueprint);
        $pattern = $this->layouts->pattern($schema, $collection->handle(), $blueprint->handle(), [], $examples);

        return new TypeSurvey($collection->title(), $collection->handle(), $this->layouts->layout($schema, $pattern), $title, $examples !== []);
    }

    /**
     * The newest published entries of a collection, with what the kind
     * finder needs to know about each.
     *
     * @param  array<int, string>  $dismissed
     */
    public function kindSurvey(EntryCollection $collection, TypeRepository $types, array $dismissed): KindSurvey
    {
        $entries = Entries::query()->where('collection', $collection->handle())->where('published', true)->get()
            ->sortByDesc(fn ($entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->take(self::KIND_SAMPLE)
            ->values();

        $several = $collection->entryBlueprints()->count() > 1;

        $samples = $entries->map(function ($entry) use ($several) {
            $builder = collect($this->reader->read($entry->blueprint()))->firstWhere('kind', 'blocks');
            $built = $builder ? collect((array) $entry->get($builder['handle']))->filter(fn ($block) => is_array($block) && ($block['enabled'] ?? true) !== false)->pluck('type')->filter(fn ($type) => is_string($type))->all() : [];

            return new KindSample(
                (string) $entry->id(),
                (string) $entry->get('title'),
                $this->prose->fromEntry($entry),
                array_values($built),
                $entry->parent()?->title(),
                $entry->blueprint()->handle(),
                $several ? $entry->blueprint()->title() : null,
            );
        })->all();

        return new KindSurvey($collection->title(), $collection->handle(), $samples, $this->kinds($types->forCollection($collection->handle())), $dismissed);
    }

    /**
     * @param  array<int, string>  $collections  Handles of the collections to plan for.
     * @param  array<int, Idea>  $plan  Ideas already on the plan, whatever their status.
     */
    public function planContext(array $collections, TypeRepository $types, array $plan, string $voice, string $steer): PlanContext
    {
        $groups = collect($collections)->map(function (string $handle) use ($types) {
            $collection = Collections::findByHandle($handle);

            if (! $collection) {
                return null;
            }

            $items = Entries::query()->where('collection', $handle)->get()->map(function ($entry) {
                $summary = $entry->get('summary') ?? $entry->get('seo_description');

                return new PlanItem((string) $entry->get('title'), (bool) $entry->published(), is_string($summary) && $summary !== '' ? Str::limit($summary, PlanItem::SUMMARY_LENGTH) : '');
            })->all();

            return new PlanGroup($collection->title(), $handle, $this->kinds($types->forCollection($handle)), $items);
        })->filter()->values()->all();

        $planned = array_map(fn (Idea $idea) => new PlannedIdea($idea->title, $idea->group, $idea->status), $plan);

        return new PlanContext($groups, $planned, $voice, $steer, (int) config('ghostwriter.plan.suggestions', 8));
    }

    /**
     * Each with the asset it came from, when known, so the model-input
     * guard can check its ledger record and file name: the sample sent is
     * a smaller copy, without the original's embedded credit.
     *
     * @param  array<int, array{label: string, entry: string, image: Image, asset?: Asset}>  $samples
     * @return array<int, ImagerySample>
     */
    public function imagerySamples(array $samples): array
    {
        return array_map(function (array $sample) {
            $asset = $sample['asset'] ?? null;

            return new ImagerySample($sample['label'], $sample['entry'], $sample['image'], $asset ? Ledger::ref($asset) : null, $asset?->basename());
        }, array_values($samples));
    }

    /**
     * The titles of a collection's newest entries, any status, for the brief writer.
     *
     * @return array<int, string>
     */
    public function briefTitles(ContentType $type): array
    {
        return Entries::query()->where('collection', $type->group)->get()
            ->sortByDesc(fn ($entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->take(self::BRIEF_TITLES)
            ->map(fn ($entry) => (string) $entry->get('title'))
            ->values()
            ->all();
    }

    /**
     * The fields and the entries to follow for a type, as the writer is shown them.
     */
    public function layout(ContentType $type): Layout
    {
        $blueprint = TypeRepository::blueprintOf($type)
            ?? throw new InvalidArgumentException("The collection \"{$type->group}\" no longer exists.");

        $schema = $this->reader->schema($blueprint);

        return $this->layouts->layout($schema, $this->layouts->pattern($schema, $type->group, $type->variant, $type->where, $type->examples));
    }

    public function kind(ContentType $type): ContentKind
    {
        return $type->toStudio();
    }

    public function conversation(Session $session): Conversation
    {
        return new Conversation($session->messages, $session->draft, $session->answers);
    }

    /**
     * @param  Collection<int, ContentType>  $types
     * @return array<int, ContentKind>
     */
    private function kinds(Collection $types): array
    {
        return $types->map(fn (ContentType $type) => $this->kind($type))->values()->all();
    }
}
