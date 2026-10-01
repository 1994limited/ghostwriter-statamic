<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Blueprints\KindFinder;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\AnalyseCollection;
use NineteenNinetyFour\Ghostwriter\Jobs\SuggestKinds;
use NineteenNinetyFour\Ghostwriter\Planning\IdeaRepository;
use NineteenNinetyFour\Ghostwriter\Sessions\SessionRepository;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
use NineteenNinetyFour\Ghostwriter\Types\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\Types\TypeState;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;
use Statamic\Contracts\Entries\Collection as StatamicCollection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\User;

/**
 * What the Ghostwriter panel needs to know about a collection, and the
 * request that teaches it a new kind of content there.
 */
class CollectionController
{
    public function __construct(
        private Studio $studio,
        private TypeRepository $types,
        private TypeState $state,
        private VoiceGuide $guide,
        private SessionRepository $sessions,
        private Presenter $presenter,
        private KindFinder $kinds,
        private SchemaReader $reader,
        private KindSuggestions $suggestions,
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
        abort_if($this->state->get($collection)['status'] === TypeState::WORKING, 409, 'Ghostwriter is already learning this collection.');

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:60'],
            'examples' => ['nullable', 'array', 'max:6'],
            'examples.*' => ['string'],
        ]);

        $examples = collect($validated['examples'] ?? [])
            ->filter(fn (string $id) => Entries::find($id)?->collectionHandle() === $collection)
            ->values()
            ->all();

        $this->state->set($collection, TypeState::WORKING);

        AnalyseCollection::start($collection, $validated['title'] ?? null, $examples);

        return response()->json($this->payload($collection));
    }

    /**
     * What has been suggested for a collection, and whether a check is running.
     */
    public function kinds(string $collection): JsonResponse
    {
        $this->ensureEnabled($collection);

        return response()->json(['kinds' => $this->kindState($collection)]);
    }

    /**
     * Look over a collection for kinds of content worth teaching.
     */
    public function suggestKinds(string $collection): JsonResponse
    {
        $this->ensureEnabled($collection);

        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');

        if ($this->suggestions->get($collection)['status'] !== KindSuggestions::WORKING) {
            $this->suggestions->update($collection, ['status' => KindSuggestions::WORKING, 'error' => null]);

            SuggestKinds::start([$collection]);
        }

        return response()->json(['kinds' => $this->kindState($collection)]);
    }

    /**
     * Learn a suggested kind: its name and the entries that show it go to
     * the same job as teaching one by hand.
     */
    public function learnKind(string $collection, string $id): JsonResponse
    {
        $this->ensureEnabled($collection);

        $suggestion = $this->suggestions->find($collection, $id) ?? abort(404, 'That suggestion has gone.');

        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');
        abort_if($this->state->get($collection)['status'] === TypeState::WORKING, 409, 'Ghostwriter is already learning a kind in this collection. Try again in a minute.');

        $this->state->set($collection, TypeState::WORKING);

        AnalyseCollection::start($collection, $suggestion['title'], $suggestion['examples']);

        $this->suggestions->remove($collection, $id);

        return response()->json(['kinds' => $this->kindState($collection), 'state' => $this->state->get($collection)]);
    }

    /**
     * Learn every kind suggested for a collection, one after another.
     */
    public function learnAllKinds(string $collection): JsonResponse
    {
        $this->ensureEnabled($collection);

        $suggestions = $this->suggestions->get($collection)['suggestions'];

        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');
        abort_if($suggestions === [], 422, 'There is nothing suggested to learn.');
        abort_if($this->state->get($collection)['status'] === TypeState::WORKING, 409, 'Ghostwriter is already learning a kind in this collection. Try again in a minute.');

        $this->state->set($collection, TypeState::WORKING);

        AnalyseCollection::start($collection, null, [], array_map(fn (array $suggestion) => ['title' => $suggestion['title'], 'examples' => $suggestion['examples']], $suggestions));

        foreach ($suggestions as $suggestion) {
            $this->suggestions->remove($collection, $suggestion['id']);
        }

        return response()->json(['kinds' => $this->kindState($collection), 'state' => $this->state->get($collection)]);
    }

    /**
     * Turn a suggestion down. It is remembered, so it is not suggested again.
     */
    public function dismissKind(string $collection, string $id): JsonResponse
    {
        $this->ensureEnabled($collection);

        $this->suggestions->remove($collection, $id, dismissed: true);

        return response()->json(['kinds' => $this->kindState($collection)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function kindState(string $collection): array
    {
        $state = $this->suggestions->get($collection);

        return [
            'status' => $state['status'],
            'error' => $state['error'],
            'checked_at' => $state['checked_at'],
            'suggestions' => array_map(fn (array $suggestion) => $suggestion + [
                'titles' => array_values(array_filter(array_map(fn (string $id) => Entries::find($id)?->get('title'), $suggestion['examples']))),
                'learn_url' => cp_route('ghostwriter.kinds.learn', [$collection, $suggestion['id']]),
                'dismiss_url' => cp_route('ghostwriter.kinds.dismiss', [$collection, $suggestion['id']]),
            ], $state['suggestions']),
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
            'has_voice' => $this->guide->exists(),
            'voice_url' => cp_route('ghostwriter.voice.show'),
            'collection' => ['handle' => $handle, 'title' => $collection->title()],
            'state' => $this->state->get($handle),
            'kinds_suggested' => $this->kindState($handle),
            // On a form for one blueprint, only the types written for it.
            'types' => $types
                ->filter(fn (ContentType $type) => ! $blueprint || ! $type->blueprint || $type->blueprint === $blueprint)
                ->map(fn (ContentType $type) => $type->forQuestionnaire())
                ->values(),
            // Kinds of entry found by how the existing ones are built, offered
            // as ready-made models for something new.
            'kinds' => ($schemaSource = $blueprint ? $collection->entryBlueprint($blueprint) : $collection->entryBlueprint())
                ? $this->kinds->find($handle, $this->reader->read($schemaSource), $blueprint)
                : [],
            'entries' => $this->entries($collection),
            // Ideas from the content plan waiting to be written here.
            'ideas' => app(IdeaRepository::class)->all()
                ->filter(fn (array $idea) => $idea['collection'] === $handle && $idea['status'] === IdeaRepository::OPEN)
                ->map(fn (array $idea) => array_intersect_key($idea, array_flip(['id', 'title', 'type', 'why', 'notes'])))
                ->values(),
            'sessions' => $this->sessions->visibleTo(User::current())
                ->filter(fn ($session) => $types->has($session->type) && $session->entryId === null && $session->source === null)
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

    private function ensureEnabled(string $collection): void
    {
        abort_unless(Collection::findByHandle($collection) && $this->types->enabled($collection), 404);
    }
}
