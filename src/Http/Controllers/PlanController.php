<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionAccess;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\SuggestIdeas;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\WorkStates;
use Statamic\Contracts\Entries\Collection;
use Statamic\Facades\Collection as Collections;

/**
 * The content plan: ideas for what the site is missing, each one a click
 * away from a filled-in brief.
 */
class PlanController
{
    public function __construct(
        private Plan $plan,
        private WorkStates $states,
        private TypeRepository $types,
        private Studio $studio,
        private SessionStore $sessions,
        private Presenter $presenter,
        private DomainOptions $options,
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
        if ($this->plan->state()->hasFailed()) {
            $this->plan->changeState(fn (PlanState $state) => $state->forgetFailure());
        }
    }

    public function status(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function suggest(Request $request): JsonResponse
    {
        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');
        abort_if($this->states->plan()->isWorking(), 409, 'Ghostwriter is already looking for ideas.');

        $validated = $request->validate([
            'collections' => ['nullable', 'array'],
            'collections.*' => ['string'],
            'steer' => ['nullable', 'string', 'max:2000'],
        ]);

        $enabled = $this->types->collections()->map->handle();
        $collections = $enabled->intersect($validated['collections'] ?? $enabled->all())->values()->all();

        abort_if($collections === [], 422, 'Choose at least one collection to plan for.');

        $this->refused(fn () => $this->plan->begin());

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
        $pending = $this->plan->state()->pending;

        $validated = $request->validate([
            'chosen' => ['present', 'array'],
            'chosen.*' => ['integer', 'min:0', 'max:'.max(count($pending) - 1, 0)],
            'discard' => ['nullable', 'boolean'],
        ]);

        if ($validated['discard'] ?? false) {
            $this->refused(fn () => $this->plan->drop());
        } else {
            $this->refused(fn () => $this->plan->keep($validated['chosen'], now()));
        }

        return response()->json($this->payload());
    }

    public function store(Request $request): JsonResponse
    {
        $this->plan->add($request->validate($this->rules(required: true)), now());

        return response()->json($this->payload());
    }

    public function update(Request $request, string $idea): JsonResponse
    {
        $validated = $request->validate($this->rules(required: false) + [
            'status' => ['sometimes', Rule::in([Idea::OPEN, Idea::DISMISSED])],
        ]);

        $this->refused(function () use ($idea, $validated) {
            $words = array_diff_key($validated, ['status' => 1]);

            if ($words !== [] || ! isset($validated['status'])) {
                $this->plan->edit($idea, $words);
            }

            // Dismissed, or put back on the plan: a dismissed idea, or a
            // piece started and given up on (E5). A finished one stays (E8).
            match ($validated['status'] ?? null) {
                Idea::DISMISSED => $this->plan->dismiss($idea),
                Idea::OPEN => $this->plan->putBack($idea, fn (Idea $started) => $this->finished($started)),
                default => null,
            };
        });

        return response()->json($this->payload());
    }

    /**
     * Empty the list: every idea in the given state goes. Started pieces
     * are never cleared this way; they are removed with their conversation.
     */
    public function clear(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in([Idea::OPEN, Idea::DISMISSED])]]);

        $this->refused(fn () => $this->plan->clear($validated['status']));

        return response()->json($this->payload());
    }

    public function destroy(string $idea): JsonResponse
    {
        $this->plan->delete($idea);

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
        $state = $this->states->plan();

        return [
            'status' => $state->status,
            'error' => $state->error,
            'pending' => array_map(fn (array $idea) => $idea + [
                'collection_title' => Collections::findByHandle((string) ($idea['collection'] ?? ''))?->title() ?? ($idea['collection'] ?? null),
                'type_title' => ($idea['type'] ?? null) ? $this->types->find((string) $idea['type'])?->title : null,
            ], $state->pending),
            // A piece whose conversation was removed is back to being just an idea.
            'ideas' => array_map(fn (Idea $idea) => $this->present($idea), $this->plan->ideas(fn (int|string $session) => $this->sessions->find((string) $session) !== null)),
        ];
    }

    /**
     * Core's refusals as the answers the plan screen expects.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function refused(callable $work): mixed
    {
        try {
            return $work();
        } catch (NotFound) {
            abort(404);
        } catch (Refused $refused) {
            abort($refused->status(), $refused->getMessage());
        }
    }

    /**
     * Whether the piece started from an idea is finished: its entry saved
     * (E6). One whose conversation has gone is not.
     */
    private function finished(Idea $idea): bool
    {
        $session = $idea->session !== null ? $this->sessions->find((string) $idea->session) : null;

        return $session !== null && $this->presenter->summary($session)['finished'];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Idea $found): array
    {
        $idea = [
            'id' => (string) $found->id,
            'title' => $found->title,
            'collection' => $found->group,
            'type' => $found->kind,
            'why' => $found->why,
            'notes' => $found->notes,
            'status' => $found->status,
            'source' => $found->source,
            'session' => $found->session === null ? null : (string) $found->session,
            'created_at' => $found->createdAt,
        ];

        $collection = Collections::findByHandle($idea['collection']);
        $type = $idea['type'] ? $this->types->find($idea['type']) : null;

        $session = $idea['session'] ? $this->sessions->find($idea['session']) : null;
        $session?->recoverIfStale($this->options, now());
        $progress = $session ? $this->presenter->summary($session) : null;

        // Another person's conversation shows where the piece has got to; it
        // is theirs to open too when conversations are shared.
        $mine = $session && (new SessionAccess($this->options))->canSee($session, Presenter::viewer());

        return $idea + [
            // Where a started piece has got to, and where to pick it up.
            'stage' => $progress['stage'] ?? null,
            'finished' => $progress['finished'] ?? false,
            'resume_url' => $mine ? ($progress['url'] ?? null) : null,
            'entry_url' => $progress['entry_url'] ?? null,
            'started_by' => $progress['started_by'] ?? null,
            'touched_by' => $progress['touched_by'] ?? null,
            'collection_title' => $collection?->title() ?? $idea['collection'],
            'type_title' => $type?->title,
            'update_url' => cp_route('ghostwriter.plan.update', $idea['id']),
            // Opens the create screen with Ghostwriter on it and this idea's brief filling itself in.
            'draft_url' => $collection && $this->types->enabled($idea['collection'])
                ? $collection->createEntryUrl().'?'.http_build_query(array_filter(['blueprint' => $type?->variant, 'ghostwriter' => 'new', 'idea' => $idea['id']]))
                : null,
        ];
    }
}
