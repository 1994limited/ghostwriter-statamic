<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\SuggestIdeas;
use NineteenNinetyFour\Ghostwriter\Planning\IdeaRepository;
use NineteenNinetyFour\Ghostwriter\Planning\PlanState;
use NineteenNinetyFour\Ghostwriter\Sessions\SessionRepository;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Collection;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\User;

/**
 * The content plan: ideas for what the site is missing, each one a click
 * away from a filled-in brief.
 */
class PlanController
{
    public function __construct(
        private IdeaRepository $ideas,
        private PlanState $state,
        private TypeRepository $types,
        private Studio $studio,
        private SessionRepository $sessions,
        private Presenter $presenter,
    ) {}

    public function show(): Response
    {
        $plan = $this->payload();

        $this->forgetFailure();

        return Inertia::render('ghostwriter::Plan', [
            'configured' => $this->studio->configured(),
            'provider' => $this->studio->provider(),
            'collections' => $this->types->collections()->map(fn (Collection $collection) => [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'types' => $this->types->forCollection($collection->handle())->map(fn (ContentType $type) => ['handle' => $type->handle, 'title' => $type->title])->values(),
            ])->values(),
            'plan' => $plan,
            'urls' => [
                'index' => cp_route('ghostwriter.index'),
                'status' => cp_route('ghostwriter.plan.status'),
                'suggest' => cp_route('ghostwriter.plan.suggest'),
                'ideas' => cp_route('ghostwriter.plan.store'),
                'clear' => cp_route('ghostwriter.plan.clear'),
                'accept' => cp_route('ghostwriter.plan.accept'),
            ],
        ]);
    }

    /**
     * A failure is reported once. After that the screen starts clean, so an
     * old error does not greet every visit.
     */
    private function forgetFailure(): void
    {
        if ($this->state->get()['status'] === PlanState::FAILED) {
            $this->state->update(['status' => PlanState::IDLE, 'error' => null, 'task' => null]);
        }
    }

    public function status(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function suggest(Request $request): JsonResponse
    {
        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');
        abort_if($this->state->get()['status'] === PlanState::WORKING, 409, 'Ghostwriter is already looking for ideas.');

        $validated = $request->validate([
            'collections' => ['nullable', 'array'],
            'collections.*' => ['string'],
            'steer' => ['nullable', 'string', 'max:2000'],
        ]);

        $enabled = $this->types->collections()->map->handle();
        $collections = $enabled->intersect($validated['collections'] ?? $enabled->all())->values()->all();

        abort_if($collections === [], 422, 'Choose at least one collection to plan for.');

        $this->state->update(['status' => PlanState::WORKING, 'error' => null, 'task' => 'suggest']);

        SuggestIdeas::start($collections, (string) ($validated['steer'] ?? ''));

        return response()->json($this->payload());
    }

    /**
     * The person has looked over what was suggested: the ones they ticked
     * join the plan, the rest are kept as dismissed so they are not
     * suggested again. Nothing chosen at all just drops the lot.
     */
    public function accept(Request $request): JsonResponse
    {
        $pending = $this->state->get()['pending'];

        $validated = $request->validate([
            'chosen' => ['present', 'array'],
            'chosen.*' => ['integer', 'min:0', 'max:'.max(count($pending) - 1, 0)],
            'discard' => ['nullable', 'boolean'],
        ]);

        if (! ($validated['discard'] ?? false)) {
            foreach ($pending as $i => $idea) {
                $added = $this->ideas->add($idea, 'suggested');

                if (! in_array($i, $validated['chosen'], true)) {
                    $this->ideas->update($added['id'], ['status' => IdeaRepository::DISMISSED]);
                }
            }
        }

        $this->state->update(['pending' => []]);

        return response()->json($this->payload());
    }

    public function store(Request $request): JsonResponse
    {
        $this->ideas->add($request->validate($this->rules(required: true)));

        return response()->json($this->payload());
    }

    public function update(Request $request, string $idea): JsonResponse
    {
        abort_unless($this->ideas->find($idea), 404);

        $this->ideas->update($idea, $request->validate($this->rules(required: false) + [
            'status' => ['sometimes', Rule::in([IdeaRepository::OPEN, IdeaRepository::DISMISSED, IdeaRepository::DRAFTED])],
        ]));

        return response()->json($this->payload());
    }

    /**
     * Empty the list: every idea in the given state goes. Started pieces
     * are never cleared this way; they are removed with their conversation.
     */
    public function clear(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in([IdeaRepository::OPEN, IdeaRepository::DISMISSED])]]);

        $this->ideas->clear($validated['status']);

        return response()->json($this->payload());
    }

    public function destroy(string $idea): JsonResponse
    {
        $this->ideas->delete($idea);

        return response()->json($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $required): array
    {
        $must = $required ? 'required' : 'sometimes';

        return [
            'title' => [$must, 'string', 'max:200'],
            'collection' => [$must, 'string', Rule::in($this->types->collections()->map->handle()->all())],
            'type' => ['nullable', 'string', 'max:100'],
            'why' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $state = $this->state->get();

        return [
            'status' => $state['status'],
            'error' => $state['error'],
            'pending' => array_map(fn (array $idea) => $idea + [
                'collection_title' => Collections::findByHandle($idea['collection'])?->title() ?? $idea['collection'],
                'type_title' => $idea['type'] ? $this->types->find($idea['type'])?->title : null,
            ], $state['pending']),
            'ideas' => $this->ideas->all()->values()->map(fn (array $idea) => $this->present($idea))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $idea
     * @return array<string, mixed>
     */
    private function present(array $idea): array
    {
        $collection = Collections::findByHandle($idea['collection']);
        $type = $idea['type'] ? $this->types->find($idea['type']) : null;

        $session = $idea['session'] ? $this->sessions->find($idea['session']) : null;
        $progress = $session ? $this->presenter->summary($session) : null;

        // Another person's conversation shows where the piece has got to, but
        // is not theirs to open.
        $mine = $session?->belongsTo(User::current()) ?? false;

        // A piece whose conversation was removed is back to being just an idea.
        if ($idea['status'] === IdeaRepository::DRAFTED && ! $session) {
            $idea['status'] = IdeaRepository::OPEN;
        }

        return $idea + [
            // Where a started piece has got to, and where to pick it up.
            'stage' => $progress['stage'] ?? null,
            'finished' => $progress['finished'] ?? false,
            'resume_url' => $mine ? ($progress['url'] ?? null) : null,
            'entry_url' => $progress['entry_url'] ?? null,
            'collection_title' => $collection?->title() ?? $idea['collection'],
            'type_title' => $type?->title,
            'update_url' => cp_route('ghostwriter.plan.update', $idea['id']),
            // Opens the create screen with Ghostwriter on it and this idea's brief filling itself in.
            'draft_url' => $collection && $this->types->enabled($idea['collection'])
                ? $collection->createEntryUrl().'?'.http_build_query(array_filter(['blueprint' => $type?->blueprint, 'ghostwriter' => 'new', 'idea' => $idea['id']]))
                : null,
        ];
    }
}
