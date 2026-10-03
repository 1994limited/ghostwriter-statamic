<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Contracts\EntryWriter;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionImages;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntryMerger;
use NineteenNinetyFour\Ghostwriter\Core\Text\HtmlToMarkdown;
use NineteenNinetyFour\Ghostwriter\Drafts\FormBaseline;
use NineteenNinetyFour\Ghostwriter\Drafts\HouseFinish;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Jobs\GenerateImage;
use NineteenNinetyFour\Ghostwriter\Jobs\RunSessionTurn;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function store(Request $request, string $type): JsonResponse
    {
        $type = $this->type($type);

        $this->ensureConfigured();

        $validated = $request->validate(TypeRepository::rules($type) + [
            'examples' => ['nullable', 'array', 'max:6'],
            'examples.*' => ['string'],
            'idea' => ['nullable', 'string', 'max:40'],
        ]);

        $answers = array_map('strval', array_filter((array) ($validated['answers'] ?? []), fn ($answer) => $answer !== null));

        // Entries to model this one piece on; only ones from its own collection.
        $examples = collect($validated['examples'] ?? [])
            ->filter(fn (string $id) => Entry::find($id)?->collectionHandle() === $type->group)
            ->values()
            ->all();

        $session = Session::start(Format::Statamic, $type->handle, $answers, $this->me(), $examples, now()->toImmutable());
        $session = $this->sessions->start($session, $this->studio->brief($type, $session), $this->viewer());

        // Started from the content plan: that idea is now in hand.
        if (! empty($validated['idea'])) {
            try {
                app(Plan::class)->start($validated['idea'], $session->id);
            } catch (NotFound) {
                // Gone from the plan meanwhile: the piece is started all the same.
            }
        }

        RunSessionTurn::start($session->id);

        return response()->json($this->presenter->detail($session));
    }

    /**
     * A first attempt at the questionnaire from a title and a few notes. It
     * only fills in the form; nothing is started until the person says so.
     */
    public function brief(Request $request, string $type): JsonResponse
    {
        $type = $this->type($type);

        $this->ensureConfigured();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ]);

        try {
            return response()->json(['answers' => $this->studio->draftBrief($type, $validated['title'], (string) ($validated['notes'] ?? ''))]);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }
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

        $session = $this->guarded(fn () => $this->sessions->send($session->id, $validated['message'], $this->viewer()));

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

        RunSessionTurn::start($session->id);

        return response()->json($this->presenter->detail($session));
    }

    public function draft(Request $request, string $session): JsonResponse
    {
        $session = $this->session($session);

        $session = $this->guarded(fn () => $this->sessions->edit($session->id, $this->viewer(), function (Session $session) use ($request) {
            $validated = $request->validate(['draft' => ['required', 'string', 'max:120000']]);

            $session->draft = $validated['draft'];
        }, self::BUSY_DRAFT));

        return response()->json($this->presenter->detail($session));
    }

    /**
     * The draft as values for the publish form the panel is open on. Nothing
     * is saved: the person reviews the filled-in form and saves it themselves.
     */
    public function apply(Request $request, string $session, SchemaReader $reader, EntryLayouts $layouts, ImageStudio $images, EntryMerger $merger, HouseFinish $finish, FormBaseline $baseline, EntryGaps $gaps): JsonResponse
    {
        $session = $this->session($session);
        $type = $this->type($session->kind)->forSession($session);
        $draft = $this->parsedDraft($session);

        // The form being filled decides the blueprint; the type's own is the
        // fallback for a collection with only one.
        $blueprint = ($request->input('blueprint') ? TypeRepository::collectionOf($type)?->entryBlueprint($request->input('blueprint')) : null)
            ?? TypeRepository::blueprintOf($type)
            ?? abort(422, 'The collection this was written for no longer exists.');
        $specs = $reader->read($blueprint);
        $schema = Schema::fromSpecs($specs);

        $original = $session->source !== null ? Entry::find((string) $session->source) : null;

        // Filling a form the person could not save is pointless, and the
        // draft is theirs to see only where they could use it.
        abort_unless($original
            ? User::current()?->can('edit', $original)
            : User::current()?->can('create', [EntryContract::class, $blueprint->parent()]), 403, 'You cannot save entries in this collection.');

        if ($original) {
            // Editing an entry: only the writing changes. Its images, links,
            // settings and block IDs come from the form as it stands, unsaved
            // changes included, not from what this kind of entry usually has.
            $built = $layouts->build($draft->data, $schema);
            $data = $merger->merge($built->data, $baseline->data($original, $request->input('values')), $specs);
            $notes = $built->notes;
            $left = SessionGaps::fromDraft($built);
        } else {
            $pattern = $layouts->pattern($schema, $type->group, $type->variant, $type->where, $type->examples);
            $built = $layouts->build($draft->data, $schema, $pattern, $type->defaults);
            $data = $built->data;

            // An image already chosen in the form stays: no placeholder,
            // nor anything the model entries suggest, goes over it. One
            // chosen in the panel still goes in below.
            $form = $baseline->values($blueprint, $request->input('values'));

            foreach ($schema as $field) {
                if ($field->type === 'assets' && ! empty($form[$field->handle])) {
                    $data[$field->handle] = $form[$field->handle];
                }
            }

            // What the model entries agree on place by place, and a striped
            // placeholder where an image is still to come. The entry has no
            // ID yet, so links to itself wait.
            $finished = $finish->finish($data, $schema, $pattern, null, $draft->title());
            $data = $finished['data'];
            $notes = [...$built->notes, ...$finished['notes']];
            $left = SessionGaps::fromDraft($built, $finished['places'], $finished['placeholders']);
        }

        $data = $images->place(['title' => $draft->title()] + $data, $session, $specs);

        // Run the data through each fieldtype's own pre-processing, so the
        // form receives exactly what it would have loaded from a saved entry.
        $fields = $blueprint->fields()->addValues($data)->preProcess();

        // Noted so the session can be shown as handed over, not still in
        // progress, on the session as it stands now, with what the draft
        // left for a person (Finish this page's messages read it).
        $viewer = $this->viewer();
        $this->sessions->change($session->id, function (Session $stored) use ($viewer, $left): void {
            $stored->markApplied($viewer->id, now()->toImmutable());
            $stored->gaps = $left->toArray();
        });

        // What is still to finish in the entry as the form will hold it,
        // for the count by Save and the guide, which opens now.
        $report = $gaps->find($gaps->context($blueprint, ['title' => $draft->title()] + $data, $original ? null : $type->group, $original ? (string) $original->id() : null, $left));

        return response()->json([
            'values' => $fields->values()->only(array_keys($data))->all(),
            'meta' => $fields->meta()->only(array_keys($data))->all(),
            'notes' => $notes,
            'gaps' => $gaps->present($report, $blueprint),
        ]);
    }

    /**
     * Make an image for one of the draft's image fields. `source` is an
     * image of the editor's own, such as a logo, to build the picture around.
     */
    public function image(Request $request, string $session, ImageStudio $images): JsonResponse
    {
        $session = $this->session($session);
        $type = $this->type($session->kind)->forSession($session);

        abort_unless($images->configured(), 422, 'No image provider has an API key. Set OPENAI_API_KEY or GEMINI_API_KEY.');

        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys($images->slots($session, $type)))],
            'direction' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ]);

        abort_if(SessionImages::status($session, $validated['key']) === SessionImages::WORKING, 409, 'That image is already being made.');

        $this->ensureCanUploadTo($images->slots($session, $type)[$validated['key']]['container']);

        $source = null;

        if ($upload = $request->file('source')) {
            $directory = storage_path('ghostwriter/uploads');
            File::ensureDirectoryExists($directory);

            $source = $upload->move($directory, Str::ulid().'.'.$upload->extension())->getPathname();
        }

        // Under the lock, so the image's record is not lost to a turn or
        // another request saving at the same moment.
        try {
            $session = $this->guarded(fn () => $this->sessions->change($session->id, function (Session $session) use ($validated) {
                SessionImages::startMaking($session, $validated['key']);
                $session->touch($this->me());
            })) ?? abort(404);
        } catch (HttpException $exception) {
            if ($source !== null) {
                File::delete($source);
            }

            throw $exception;
        }

        GenerateImage::start($session->id, $validated['key'], (string) ($validated['direction'] ?? ''), $source);

        return response()->json($this->presenter->detail($session));
    }

    /**
     * One piece of writing in the draft, changed where it is shown, without
     * opening the YAML. Rich text comes back as HTML and is turned into the
     * markdown the draft is written in.
     */
    public function editField(Request $request, string $session, HtmlToMarkdown $html): JsonResponse
    {
        $session = $this->session($session);

        $session = $this->guarded(fn () => $this->sessions->edit($session->id, $this->viewer(), function (Session $session) use ($request, $html) {
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

            $session->draft = trim(Yaml::dump($data, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        }, self::BUSY_DRAFT));

        return response()->json($this->presenter->detail($session));
    }

    /**
     * Free-to-use photographs that might suit one of the draft's image fields.
     */
    public function photos(Request $request, string $session, ImageStudio $images): JsonResponse
    {
        $session = $this->session($session);
        $type = $this->type($session->kind)->forSession($session);

        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys($images->slots($session, $type)))],
            'query' => ['nullable', 'string', 'max:200'],
        ]);

        // Without words, the searches are chosen from the draft. The photos
        // that suit the page and the site's own images come first.
        return response()->json(ImageStudio::offered($images->photos($session, $type, $validated['key'], $validated['query'] ?? null)));
    }

    /**
     * Bring a chosen photograph into the asset container as a field's image.
     */
    public function photo(Request $request, string $session, ImageStudio $images, StockSearch $stock): JsonResponse
    {
        $session = $this->session($session);
        $type = $this->type($session->kind)->forSession($session);

        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys($images->slots($session, $type)))],
            'source' => ['required', 'string', Rule::in($stock->sources())],
            'id' => ['required', 'string', 'max:64'],
            'term' => ['nullable', 'string', 'max:200'],
        ]);

        $this->ensureCanUploadTo($images->slots($session, $type)[$validated['key']]['container']);

        try {
            $file = $stock->fetch($validated['source'], $validated['id']);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        $asset = $images->keep($session, $type, $validated['key'], $file, $validated['term'] ?? null);

        // Fetching took a while: the choice is written to the session as it
        // stands now, so a turn that saved meanwhile is not undone, nor
        // undoes it.
        $session = $this->guarded(fn () => $this->sessions->change($session->id, function (Session $session) use ($validated, $asset, $file) {
            SessionImages::choose($session, $validated['key'], $asset->path(), $asset->url(), $file->photo->credit);
            $session->touch($this->me());
        })) ?? abort(404);

        return response()->json($this->presenter->detail($session));
    }

    /**
     * Use the image already chosen for one field in another as well, as
     * sites often do with a hero image and a thumbnail.
     */
    public function copyImage(Request $request, string $session, ImageStudio $images): JsonResponse
    {
        $session = $this->session($session);
        $slots = $images->slots($session, $this->type($session->kind)->forSession($session));

        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys($slots))],
            'from' => ['required', 'string', 'different:key', Rule::in(array_keys($slots))],
        ]);

        $source = $session->images[$validated['from']] ?? [];

        abort_unless(($source['status'] ?? null) === SessionImages::DONE && ! empty($source['path']), 422, 'That field has no image yet.');
        abort_unless($slots[$validated['key']]['container'] === $slots[$validated['from']]['container'], 422, 'Those two fields keep their images in different places.');

        // On the session as it stands now. The field keeps the photographs
        // it was offered, in case of a change of mind.
        $session = $this->guarded(fn () => $this->sessions->change($session->id, function (Session $session) use ($validated) {
            try {
                SessionImages::copy($session, $validated['key'], $validated['from']);
            } catch (Conflict $exception) {
                abort(422, $exception->getMessage());
            }

            $session->touch($this->me());
        })) ?? abort(404);

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

    public function destroy(string $session): JsonResponse
    {
        $session = $this->session($session);

        $this->guarded(fn () => $this->sessions->delete($session->id, $this->viewer(), 'Only the person who started this piece, or someone who manages Ghostwriter, can delete it.'));

        return response()->json(['deleted' => true]);
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

    /**
     * Saving an image into a container is uploading to it, and needs the
     * same permission as uploading by hand.
     */
    private function ensureCanUploadTo(string $container): void
    {
        $found = AssetContainer::find($container) ?? abort(422, "The asset container \"{$container}\" no longer exists.");

        abort_unless(User::current()?->can('store', [Asset::class, $found]), 403, 'You cannot upload to the '.$found->title().' container.');
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
