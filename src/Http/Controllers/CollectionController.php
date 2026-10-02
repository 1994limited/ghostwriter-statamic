<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\Analysis;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\AnalyseCollection;
use NineteenNinetyFour\Ghostwriter\Jobs\SuggestKinds;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\WorkStates;
use Statamic\Contracts\Entries\Collection as StatamicCollection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as Entries;

/**
 * What the Ghostwriter panel needs to know about a collection, and the
 * request that teaches it a new kind of content there.
 */
class CollectionController
{
    public function __construct(
        private Studio $studio,
        private TypeRepository $types,
        private WorkStates $states,
        private GuideStore $guides,
        private SessionGuard $sessions,
        private Presenter $presenter,
        private EntryLayouts $layouts,
        private SchemaReader $reader,
    ) {}

    public function show(Request $request, string $collection): JsonResponse
    {
        return response()->json($this->payload($collection, $request->query('blueprint')));
    }

    /**
     * Learn a kind of content: from the collection's newest entries, or from
     * the entries named in `examples` when a collection holds several kinds.
     */
    public function analyse(Request $request, string $collection): JsonResponse
    {
        $this->ensureEnabled($collection);

        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');
        abort_if($this->states->analysis($collection)->isWorking(), 409, 'Ghostwriter is already learning this collection.');

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:60'],
            'examples' => ['nullable', 'array', 'max:6'],
            'examples.*' => ['string'],
        ]);

        $examples = collect($validated['examples'] ?? [])
            ->filter(fn (string $id) => Entries::find($id)?->collectionHandle() === $collection)
            ->values()
            ->all();

        $this->learning($collection, 'Ghostwriter is already learning this collection.');

        AnalyseCollection::start($collection, $validated['title'] ?? null, $examples);

        return response()->json($this->payload($collection));
    }

    /**
     * What has been suggested for a collection, whether a check is running,
     * and how learning a kind there stands, so the dashboard can tell when
     * "Learn this" has finished or failed.
     */
    public function kinds(string $collection): JsonResponse
    {
        $this->ensureEnabled($collection);

        return response()->json(['kinds' => $this->kindState($collection), 'state' => $this->states->analysis($collection)->toArray()]);
    }

    /**
     * Look over a collection for kinds of content worth teaching.
     */
    public function suggestKinds(string $collection): JsonResponse
    {
        $this->ensureEnabled($collection);

        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');

        if ($this->startSuggesting($collection)) {
            SuggestKinds::start([$collection]);
        }

        return response()->json(['kinds' => $this->kindState($collection)]);
    }

    /**
     * Look over every collection Ghostwriter writes for, in one go: those
     * not being looked at already are sent to one job.
     */
    public function suggestKindsEverywhere(): JsonResponse
    {
        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');

        $handles = $this->types->collections()->map->handle()->values()->all();
        $due = array_values(array_filter($handles, fn (string $handle) => $this->startSuggesting($handle)));

        if ($due !== []) {
            SuggestKinds::start($due);
        }

        return response()->json(['collections' => collect($handles)->mapWithKeys(fn (string $handle) => [$handle => ['kinds' => $this->kindState($handle)]])]);
    }

    /**
     * Learn a suggested kind: its name and the entries that show it go to
     * the same job as teaching one by hand.
     */
    public function learnKind(string $collection, string $id): JsonResponse
    {
        $this->ensureEnabled($collection);

        $suggestion = $this->states->suggestions($collection)->find($id) ?? abort(404, 'That suggestion has gone.');

        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');
        abort_if($this->states->analysis($collection)->isWorking(), 409, self::LEARNING);

        $this->learning($collection, self::LEARNING);

        AnalyseCollection::start($collection, $suggestion['title'], $suggestion['examples']);

        $this->states->changeSuggestions($collection, fn (KindSuggestions $state) => $state->remove($id));

        return response()->json(['kinds' => $this->kindState($collection), 'state' => $this->states->analysis($collection)->toArray()]);
    }

    /**
     * Learn every kind suggested for a collection, one after another.
     */
    public function learnAllKinds(string $collection): JsonResponse
    {
        $this->ensureEnabled($collection);

        $suggestions = $this->states->suggestions($collection)->suggestions;

        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');
        abort_if($suggestions === [], 422, 'There is nothing suggested to learn.');
        abort_if($this->states->analysis($collection)->isWorking(), 409, self::LEARNING);

        $this->learning($collection, self::LEARNING);

        AnalyseCollection::start($collection, null, [], array_map(fn (array $suggestion) => ['title' => $suggestion['title'], 'examples' => $suggestion['examples']], $suggestions));

        $this->states->changeSuggestions($collection, function (KindSuggestions $state) use ($suggestions) {
            foreach ($suggestions as $suggestion) {
                $state->remove((string) $suggestion['id']);
            }
        });

        return response()->json(['kinds' => $this->kindState($collection), 'state' => $this->states->analysis($collection)->toArray()]);
    }

    /**
     * Turn a suggestion down. It is remembered, so it is not suggested again.
     */
    public function dismissKind(string $collection, string $id): JsonResponse
    {
        $this->ensureEnabled($collection);

        $this->states->changeSuggestions($collection, fn (KindSuggestions $state) => $state->remove($id, dismissed: true));

        return response()->json(['kinds' => $this->kindState($collection)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function kindState(string $collection): array
    {
        $state = $this->states->suggestions($collection);

        return [
            'status' => $state->status,
            'error' => $state->error,
            'checked_at' => $state->checkedAt,
            'suggestions' => array_map(fn (array $suggestion) => $suggestion + [
                'titles' => array_values(array_filter(array_map(fn (string $id) => Entries::find($id)?->get('title'), (array) ($suggestion['examples'] ?? [])))),
                'learn_url' => cp_route('ghostwriter.kinds.learn', [$collection, $suggestion['id']]),
                'dismiss_url' => cp_route('ghostwriter.kinds.dismiss', [$collection, $suggestion['id']]),
            ], $state->suggestions),
            'urls' => [
                'status' => cp_route('ghostwriter.kinds.show', $collection),
                'suggest' => cp_route('ghostwriter.kinds.suggest', $collection),
                'learn_all' => cp_route('ghostwriter.kinds.learn_all', $collection),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $handle, ?string $blueprint = null): array
    {
        $this->ensureEnabled($handle);

        $collection = Collection::findByHandle($handle);
        $types = $this->types->offeredFor($handle);

        return [
            'configured' => $this->studio->configured(),
            'provider' => $this->studio->provider(),
            'has_voice' => $this->guides->guide(Guide::VOICE)->exists(),
            'voice_url' => cp_route('ghostwriter.voice.show'),
            'collection' => ['handle' => $handle, 'title' => $collection->title()],
            'state' => $this->states->analysis($handle)->toArray(),
            'kinds_suggested' => $this->kindState($handle),
            // On a form for one blueprint, only the types written for it.
            'types' => $types
                ->filter(fn (ContentType $type) => ! $blueprint || ! $type->variant || $type->variant === $blueprint)
                ->map(fn (ContentType $type) => TypeRepository::forQuestionnaire($type))
                ->values(),
            // Kinds of entry found by how the existing ones are built, offered
            // as ready-made models for something new.
            'kinds' => ($schemaSource = $blueprint ? $collection->entryBlueprint($blueprint) : $collection->entryBlueprint())
                ? $this->layouts->kinds($this->reader->schema($schemaSource), $handle, $blueprint)
                : [],
            'entries' => $this->entries($collection),
            // Ideas from the content plan waiting to be written here.
            'ideas' => collect(app(PlanStore::class)->ideas())
                ->filter(fn (Idea $idea) => $idea->group === $handle && $idea->isOpen())
                ->map(fn (Idea $idea) => ['id' => (string) $idea->id, 'title' => $idea->title, 'type' => $idea->kind, 'why' => $idea->why, 'notes' => $idea->notes])
                ->values(),
            'sessions' => collect($this->sessions->visible(Presenter::viewer()))
                ->filter(fn (Session $session) => $types->has($session->kind) && $session->recordId === null && $session->source === null)
                ->map(fn ($session) => $this->presenter->summary($session))
                ->reject(fn (array $session) => $session['finished'])
                ->take(8)
                ->values(),
        ];
    }

    /**
     * The entries something new can be modelled on. A structured collection
     * is listed in its own order with each entry's depth, so the picker can
     * show the site's sections; any other is listed newest first.
     *
     * @return \Illuminate\Support\Collection<int, array{id: string, title: string, published: bool, depth: int}>
     */
    private function entries(StatamicCollection $collection): \Illuminate\Support\Collection
    {
        $entries = Entries::query()->where('collection', $collection->handle())->get()->keyBy->id();

        $present = fn (Entry $entry, int $depth = 0) => [
            'id' => $entry->id(),
            'title' => (string) $entry->get('title'),
            'published' => $entry->published(),
            'depth' => $depth,
        ];

        if ($tree = $collection->structure()?->in($collection->sites()->first())) {
            return $tree->flattenedPages()
                ->filter(fn ($page) => $entries->has($page->reference()))
                ->take(200)
                ->map(fn ($page) => $present($entries->get($page->reference()), max(0, (int) $page->depth() - 1)))
                ->values();
        }

        return $entries
            ->sortByDesc(fn (Entry $entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->take(200)
            ->map(fn (Entry $entry) => $present($entry))
            ->values();
    }

    /** Said when a kind is asked for while one is being learned. */
    private const LEARNING = 'Ghostwriter is already learning a kind in this collection. Try again in a minute.';

    /**
     * Mark the collection as being studied; refused when it already is.
     */
    private function learning(string $collection, string $busy): void
    {
        try {
            $this->states->changeAnalysis($collection, fn (Analysis $state) => $state->begin(null, $busy));
        } catch (Conflict $conflict) {
            abort(409, $conflict->getMessage());
        }
    }

    /**
     * Mark the collection as having its kinds looked for, unless they
     * already are. Whether a look should start.
     */
    private function startSuggesting(string $collection): bool
    {
        if ($this->states->suggestions($collection)->isWorking()) {
            return false;
        }

        $this->states->changeSuggestions($collection, function (KindSuggestions $state) {
            $state->status = 'working';
            $state->error = null;
        });

        return true;
    }

    private function ensureEnabled(string $collection): void
    {
        abort_unless(Collection::findByHandle($collection) && $this->types->enabled($collection), 404);
    }
}
