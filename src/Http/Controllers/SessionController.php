<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Contracts\EntryWriter;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefStage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefThread;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comments;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Brief;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\HtmlToMarkdown;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftLayouts;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftValues;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Jobs\ApplyComments;
use NineteenNinetyFour\Ghostwriter\Jobs\FillBrief;
use NineteenNinetyFour\Ghostwriter\Jobs\RunSessionTurn;
use NineteenNinetyFour\Ghostwriter\Preview\PagePreview;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Fields\Blueprint;
use Symfony\Component\Yaml\Yaml;

class SessionController
{
    private const BUSY_DRAFT = 'Ghostwriter is still working on the draft. Try again when it has finished.';

    public function __construct(
        private SessionGuard $sessions,
        private TypeRepository $types,
        private Studio $studio,
        private Presenter $presenter,
    ) {}

    /**
     * A new piece. The panel asks for the quick details as soon as the kind
     * is chosen and keeps the piece only once they are given (`details`),
     * so a kind looked at and left behind leaves nothing to carry on with;
     * the brief is then filled in from them. From a plan idea ("Draft
     * this") there is no question: the brief is filled in from the idea.
     */
    public function store(Request $request, string $type, Plan $plan, PlanStore $ideas): JsonResponse
    {
        $type = $this->type($type);

        $this->ensureConfigured();

        $validated = $request->validate([
            'examples' => ['nullable', 'array', 'max:'.Brief::MAX_EXAMPLES],
            'examples.*' => ['string'],
            'idea' => ['nullable', 'string', 'max:40'],
            'details' => ['nullable', 'string', 'max:50000'],
        ]);

        $session = Session::start(Format::Statamic, $type->handle, [], $this->me(), $this->examples($type, $validated['examples'] ?? []), now()->toImmutable());
        $idea = ! empty($validated['idea']) ? $ideas->find($validated['idea']) : null;

        if ($idea === null) {
            $session = $this->sessions->open($session, $this->viewer(), __(BriefThread::ASK_TEXT));

            if (trim((string) ($validated['details'] ?? '')) !== '') {
                $session = $this->guarded(fn () => $this->sessions->details($session->id, $validated['details'], $this->viewer()));

                FillBrief::start($session->id);
            }

            return response()->json($this->presenter->detail($session));
        }

        $session = $this->sessions->openFromIdea($session, $this->viewer(), $idea->title, trim($idea->why."\n\n".$idea->notes));

        // Started from the content plan: that idea is now in hand.
        try {
            $plan->start($idea->id, $session->id);
        } catch (NotFound) {
            // Gone from the plan meanwhile: the piece is started all the same.
        }

        FillBrief::start($session->id);

        return response()->json($this->presenter->detail($session));
    }

    /**
     * "Try again": another brief from the same details, with the card as
     * the person left it (the answers they changed are kept).
     */
    public function tryAgain(Request $request, string $session): JsonResponse
    {
        $session = $this->session($session);
        $type = $this->type($session->kind);

        $this->ensureConfigured();

        $card = $this->card($request, $type);

        $session = $this->guarded(fn () => $this->sessions->tryAgain($session->id, $this->viewer(), $card['answers'], $card['examples'], $card['title'], __(BriefThread::TRY_AGAIN_TEXT)));

        FillBrief::start($session->id);

        return response()->json($this->presenter->detail($session));
    }

    /**
     * "Looks right, start writing": the card, with the person's changes, is
     * the brief. Something left in square brackets counts as an answer: it
     * becomes a gap in the draft for Finish this page.
     */
    public function agree(Request $request, string $session): JsonResponse
    {
        $session = $this->session($session);
        $type = $this->type($session->kind);

        $this->ensureConfigured();

        $card = $this->card($request, $type, $session);

        $session = $this->guarded(fn () => $this->sessions->agree($session->id, $this->viewer(), fn (Brief $brief) => $this->studio->brief($type, $brief), $card['answers'], $card['examples'], $card['title']));

        RunSessionTurn::start($session->id);

        return response()->json($this->presenter->detail($session));
    }

    /**
     * A change to the agreed brief ("Show the brief"). No turn runs: the
     * next message works from it.
     */
    public function editBrief(Request $request, string $session): JsonResponse
    {
        $session = $this->session($session);
        $type = $this->type($session->kind);

        $card = $this->card($request, $type, $session);

        $session = $this->guarded(fn () => $this->sessions->editBrief($session->id, $this->viewer(), fn (Brief $brief) => $this->studio->brief($type, $brief), $card['answers'], $card['examples'], $card['title']));

        return response()->json($this->presenter->detail($session));
    }

    /**
     * Straight to a session where it is written: the entry it edits, or its
     * collection's create screen with the panel open on it.
     */
    public function open(string $session): RedirectResponse
    {
        $url = $this->presenter->summary($this->session($session))['url'] ?? abort(404);

        return redirect($url);
    }

    public function show(string $session): JsonResponse
    {
        return response()->json($this->presenter->detail($this->session($session)));
    }

    public function message(Request $request, string $session): JsonResponse
    {
        $session = $this->session($session);

        $this->ensureConfigured();

        $this->ensureIdle($session, 'Ghostwriter is still working on the last message.');

        $validated = $request->validate(['message' => ['required', 'string', 'max:50000']]);

        // The reply to "What's it called…": the brief is filled in from it.
        if (BriefThread::stage($session) === BriefStage::Details) {
            $session = $this->guarded(fn () => $this->sessions->details($session->id, $validated['message'], $this->viewer()));

            FillBrief::start($session->id);

            return response()->json($this->presenter->detail($session));
        }

        // The brief couldn't be filled in: a little more about it, and it is
        // filled in again from everything said so far.
        if (BriefThread::stage($session) === BriefStage::Filling && $session->hasFailed()) {
            $session = $this->guarded(fn () => $this->sessions->send($session->id, $validated['message'], $this->viewer(), extra: [BriefThread::KEY => ['step' => BriefThread::DETAILS]]));

            FillBrief::start($session->id);

            return response()->json($this->presenter->detail($session));
        }

        // The brief card is waiting: it is agreed (or tried again) first.
        abort_unless(BriefThread::stage($session)->agreed(), 409, 'Check the brief first, then start writing.');

        $session = $this->guarded(fn () => $this->sessions->send($session->id, $validated['message'], $this->viewer()));

        RunSessionTurn::start($session->id);

        return response()->json($this->presenter->detail($session));
    }

    /**
     * The answers to the writer's questions (core's Studio\Asks), by
     * question id, sent as one message: an empty or missing one is
     * skipped. `more` is anything else the person added.
     */
    public function answers(Request $request, string $session): JsonResponse
    {
        $session = $this->session($session);

        $this->ensureConfigured();

        $validated = $request->validate([
            'answers' => ['present', 'array'],
            'answers.*' => ['nullable', 'string', 'max:20000'],
            'more' => ['nullable', 'string', 'max:50000'],
        ]);

        $session = $this->guarded(fn () => $this->sessions->answerQuestions(
            $session->id,
            array_map(fn (mixed $answer) => is_string($answer) ? $answer : null, $validated['answers']),
            (string) ($validated['more'] ?? ''),
            $this->viewer(),
            'Ghostwriter is still working on the last message.',
        ));

        RunSessionTurn::start($session->id);

        return response()->json($this->presenter->detail($session));
    }

    /**
     * The last turn failed: run it again, with the same message.
     */
    public function retry(string $session): JsonResponse
    {
        $session = $this->session($session);

        $this->ensureConfigured();

        // Whoever asks again is the one waiting on it now. A piece already
        // running has nothing to try again, whoever started it.
        $session = $this->guarded(function () use ($session) {
            try {
                return $this->sessions->retry($session->id, $this->viewer());
            } catch (Busy) {
                throw new Conflict('There is nothing to try again.');
            }
        });

        // Comments that weren't answered are applied again; a brief that
        // failed to fill is filled again; otherwise the turn runs again.
        if (Comments::unanswered($session) !== null) {
            ApplyComments::start($session->id);
        } else {
            FillBrief::next($session);
        }

        return response()->json($this->presenter->detail($session));
    }

    public function draft(Request $request, string $session, DraftLayouts $layouts): JsonResponse
    {
        $session = $this->session($session);

        $session = $this->guarded(fn () => $this->sessions->edit($session->id, $this->viewer(), function (Session $session) use ($request, $layouts) {
            $validated = $request->validate(['draft' => ['required', 'string', 'max:120000']]);

            $before = $session->draft;
            $session->draft = $validated['draft'];

            // The layouts follow the words; none is asked for again.
            $layouts->afterEdit($session, $before, $this->types->find($session->kind));
        }, self::BUSY_DRAFT));

        return response()->json($this->presenter->detail($session));
    }

    /**
     * The draft as values for the publish form the panel is open on. Nothing
     * is saved: the person reviews the filled-in form and saves it themselves.
     */
    public function apply(Request $request, string $session, DraftValues $values, EntryGaps $gaps, DraftLayouts $layouts): JsonResponse
    {
        // Read before the piece: its links are laid over it before the mark
        // is cleared, so a piece read after the mark is gone has them.
        $checking = DraftLayouts::isChecking($session);
        $session = $this->session($session);

        abort_if($checking, 409, 'Ghostwriter is still checking the links.');
        $type = $this->type($session->kind)->forSession($session);
        $this->parsedDraft($session);

        // The form being filled decides the blueprint; the type's own is the
        // fallback for a collection with only one.
        $blueprint = self::blueprintFor($type, $request->input('blueprint'));

        // The chosen layout of the draft's words (the writer's own unless
        // another was chosen), through the same build as ever.
        $draft = $layouts->draft($session, $type, $blueprint);

        $original = $session->source !== null ? Entry::find((string) $session->source) : null;

        // Filling a form the person could not save is pointless, and the
        // draft is theirs to see only where they could use it.
        self::ensureCanSave($original, $blueprint);

        $built = $values->build($session, $type, $draft, $blueprint, $request->input('values'), $original);
        $data = $built->data;
        $notes = $built->notes;
        $left = $built->left;

        // Run the data through each fieldtype's own pre-processing, so the
        // form receives exactly what it would have loaded from a saved entry.
        $fields = $blueprint->fields()->addValues($data)->preProcess();

        // Noted so the session can be shown as handed over, not still in
        // progress, on the session as it stands now, with what the draft
        // left for a person (Finish this page's messages read it) and the
        // SEO text Ghostwriter put in, so it is known as its own later.
        $viewer = $this->viewer();
        $written = $built->written;
        $stored = $this->sessions->change($session->id, function (Session $stored) use ($viewer, $left, $written): void {
            $stored->markApplied($viewer->id, now()->toImmutable());
            $stored->gaps = $left->toArray();

            if (! $written->isEmpty()) {
                SeoState::of($stored)->withWritten($written)->saveTo($stored);
            }
        });

        // What is still to finish in the entry as the form will hold it,
        // for the count by Save and the guide, which opens now: with the
        // links Ghostwriter added and the draft's search description, so
        // "Add a description for search" can offer it.
        $seo = SeoState::of($stored ?? $session);
        $left = new SessionGaps($left->toArray(), $seo->links, $seo->suggested, $seo->meta->toArray());
        // Under an edit, the fields the draft doesn't hold are as the form has them.
        $checked = $original ? $data + $gaps->data($blueprint, is_array($request->input('values')) ? $request->input('values') : [], $original) : $data;
        $report = $gaps->find($gaps->context($blueprint, $checked, $original ? null : $type->group, $original ? (string) $original->id() : null, $left, $original?->locale(), EntryGaps::sources($session)));

        $values = $fields->values()->only(array_keys($data))->all();

        // The address, for a new entry: into the form's own slug field.
        if ($built->slug !== null) {
            $values['slug'] = $built->slug;
        }

        return response()->json([
            'values' => $values,
            'meta' => $fields->meta()->only(array_keys($data))->all(),
            'notes' => $notes,
            'gaps' => $gaps->present($report, $blueprint),
        ]);
    }

    /**
     * One piece of writing in the draft, changed where it is shown, without
     * opening the YAML. Rich text comes back as HTML and is turned into the
     * markdown the draft is written in.
     */
    public function editField(Request $request, string $session, HtmlToMarkdown $html, DraftLayouts $layouts): JsonResponse
    {
        $session = $this->session($session);

        $session = $this->guarded(fn () => $this->sessions->edit($session->id, $this->viewer(), function (Session $session) use ($request, $html, $layouts) {
            $draft = $this->parsedDraft($session);

            $validated = $request->validate([
                'path' => ['required', 'array', 'min:1'],
                'path.*' => ['string', 'max:100'],
                'value' => ['present', 'string', 'max:60000'],
                'format' => ['nullable', Rule::in(['text', 'html'])],
            ]);

            $value = ($validated['format'] ?? 'text') === 'html' ? $html->convert($validated['value']) : trim(str_replace("\r", '', $validated['value']));

            $data = $draft->data;
            $node = &$data;

            foreach ($validated['path'] as $step) {
                $step = is_numeric($step) && is_array($node) && array_is_list($node) ? (int) $step : $step;

                if (! is_array($node) || ! array_key_exists($step, $node)) {
                    abort(422, 'That part of the draft could not be found.');
                }

                $node = &$node[$step];
            }

            // Only writing is edited here; a block or a list is changed in YAML.
            abort_if(! is_scalar($node) && $node !== null, 422, 'Only text can be edited here.');

            $node = $value;
            unset($node);

            $before = $session->draft;
            $session->draft = trim(Yaml::dump($data, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

            $layouts->afterEdit($session, $before, $this->types->find($session->kind));
        }, self::BUSY_DRAFT));

        return response()->json($this->presenter->detail($session));
    }

    /**
     * Save the draft straight to an unpublished entry, for use away from a
     * publish form.
     */
    public function entry(string $session, EntryWriter $writer, SchemaReader $reader, ImageStudio $images): JsonResponse
    {
        $session = $this->session($session);
        $type = $this->type($session->kind)->forSession($session);

        // Ghostwriter never creates what the person could not create by hand.
        $collection = TypeRepository::collectionOf($type) ?? abort(422, 'The collection this was written for no longer exists.');
        abort_unless(User::current()?->can('create', [EntryContract::class, $collection]), 403, 'You cannot create entries in this collection.');

        try {
            $entry = $writer->write($this->parsedDraft($session), $type, User::current());
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        if ($session->images && ($blueprint = $entry->blueprint())) {
            $entry->data($images->place($entry->data()->all(), $session, $reader->read($blueprint)))->save();
        }

        $session = $this->guarded(fn () => $this->sessions->change($session->id, function (Session $session) use ($entry) {
            $session->recordId = (string) $entry->id();
            $session->touch($this->me());
        })) ?? $session;

        return response()->json(['entry_url' => $entry->editUrl()] + $this->presenter->detail($session));
    }

    public function destroy(string $session, PagePreview $previews): JsonResponse
    {
        $session = $this->session($session);

        $this->guarded(fn () => $this->sessions->delete($session->id, $this->viewer(), 'Only the person who started this piece, or someone who manages Ghostwriter, can delete it.'));

        // Its page previews' tokens go with it.
        $previews->forget($session->id);

        return response()->json(['deleted' => true]);
    }

    /**
     * Entries to model a piece on: only ones from its own collection.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    private function examples(ContentType $type, array $ids): array
    {
        return collect($ids)
            ->filter(fn (string $id) => Entry::find($id)?->collectionHandle() === $type->group)
            ->values()
            ->all();
    }

    /**
     * The brief card as the person left it. Before it is agreed (or saved
     * once agreed), the required answers are checked on the card as it
     * will be, as the brief screen checked them; something in square
     * brackets counts as an answer.
     *
     * @return array{title: ?string, answers: array<string, string>, examples: ?array<int, string>}
     */
    private function card(Request $request, ContentType $type, ?Session $required = null): array
    {
        $rules = array_map(fn (array $rule) => array_map(fn (string $part) => $part === 'required' ? 'nullable' : $part, $rule), TypeRepository::rules($type));

        $validated = $request->validate($rules + [
            'title' => ['nullable', 'string', 'max:200'],
            'answers' => ['nullable', 'array'],
            'examples' => ['nullable', 'array', 'max:'.Brief::MAX_EXAMPLES],
            'examples.*' => ['string'],
        ]);

        $card = [
            'title' => isset($validated['title']) ? (string) $validated['title'] : null,
            'answers' => array_map(fn ($answer) => (string) $answer, array_map(fn ($answer) => $answer ?? '', (array) ($validated['answers'] ?? []))),
            'examples' => array_key_exists('examples', $validated) ? $this->examples($type, (array) ($validated['examples'] ?? [])) : null,
        ];

        if ($required !== null && ($brief = BriefThread::card($required)) !== null) {
            $missing = $type->missing($brief->with($card['answers'])->answers);

            if ($missing !== []) {
                throw ValidationException::withMessages(collect($missing)->mapWithKeys(fn (string $message, string $handle) => ["answers.{$handle}" => __($message)])->all());
            }
        }

        return $card;
    }

    /**
     * The blueprint a draft is put into: the form's, or the type's own for
     * a collection with only one.
     */
    public static function blueprintFor(ContentType $type, mixed $handle): Blueprint
    {
        return (is_string($handle) && $handle !== '' ? TypeRepository::collectionOf($type)?->entryBlueprint($handle) : null)
            ?? TypeRepository::blueprintOf($type)
            ?? abort(422, 'The collection this was written for no longer exists.');
    }

    /**
     * Only someone who could save the entry by hand may fill its form or
     * see the draft rendered as it.
     */
    public static function ensureCanSave(?EntryContract $original, Blueprint $blueprint): void
    {
        abort_unless($original
            ? User::current()?->can('edit', $original)
            : User::current()?->can('create', [EntryContract::class, $blueprint->parent()]), 403, 'You cannot save entries in this collection.');
    }

    private function parsedDraft(Session $session): Draft
    {
        abort_if($session->draft === null, 422, 'There is no draft yet.');

        try {
            return Draft::parse($session->draft);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }
    }

    private function type(string $handle): ContentType
    {
        $type = $this->types->find($handle);

        abort_unless($type && $this->types->enabled($type->group), 404);

        return $type;
    }

    /**
     * A session is everyone's who may use Ghostwriter when conversations are
     * shared; otherwise its starter's, and nobody else may read it, write in
     * it or use its draft, super users aside.
     */
    private function session(string $id): Session
    {
        try {
            return $this->sessions->find($id, $this->viewer());
        } catch (NotFound) {
            abort(404);
        } catch (NotAllowed) {
            abort(403);
        }
    }

    /**
     * Core's refusals as the answers the panel expects: whose request is
     * running, when it is someone else's (one run at a time).
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (Busy $busy) {
            abort(409, $busy->messageFor(fn (int|string $id) => Presenter::name((string) $id)));
        } catch (NotFound) {
            abort(404);
        } catch (Refused $refused) {
            abort($refused->status(), $refused->getMessage());
        }
    }

    private function viewer(): Viewer
    {
        return Presenter::viewer();
    }

    private function me(): ?string
    {
        $id = User::current()?->id();

        return $id === null ? null : (string) $id;
    }

    /**
     * One run at a time. While someone else's request runs, say whose.
     */
    private function ensureIdle(Session $session, string $message): void
    {
        if ($session->isWorking()) {
            $this->guarded(fn () => throw new Busy($message, $session->waitingOn($this->viewer())));
        }
    }

    private function ensureConfigured(): void
    {
        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');
    }
}
