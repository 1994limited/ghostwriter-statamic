<?php

namespace NineteenNinetyFour\Ghostwriter\Http;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\DraftPreview;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Jobs\Waiting;
use NineteenNinetyFour\Ghostwriter\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Sessions\SessionRepository;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Shapes sessions for the Control Panel.
 */
class Presenter
{
    private ?GithubFlavoredMarkdownConverter $markdown = null;

    public function __construct(
        private TypeRepository $types,
        private SchemaReader $reader,
        private DraftPreview $preview,
        private ImageStudio $images,
        private StockSearch $stock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Session $session): array
    {
        $type = $this->types->find($session->type);
        $collection = $type?->statamicCollection();

        $entry = $this->entryFor($session, $type);

        return [
            'id' => $session->id,
            'title' => $session->title(),
            'type' => $type?->title ?? $session->type,
            'collection' => $collection?->title(),
            'status' => $session->status,
            'has_draft' => $session->draft !== null,
            'stage' => $this->stage($session, $entry),
            // Done with once its entry is saved: the draft has become an
            // entry, or the changes put into an existing one's form have
            // been saved. Put into the form and not saved is not done.
            'finished' => $session->status !== Session::WORKING && $this->saved($session, $entry),
            'entry_url' => $entry?->editUrl(),
            // Shared pieces are deleted by their starter or a manager only.
            'delete_url' => app(SessionRepository::class)->canDelete($session, User::current()) ? cp_route('ghostwriter.sessions.destroy', $session->id) : null,
            'updated_at' => Carbon::parse($session->updatedAt)->diffForHumans(),
            ...$this->people($session),
            // Sessions are resumed where they were started: on the entry
            // being edited, or on the collection's create screen.
            'url' => match (true) {
                $session->source && ($entry = Entry::find($session->source)) => $entry->editUrl().'?ghostwriter='.$session->id,
                $collection !== null => $collection->createEntryUrl().'?ghostwriter='.$session->id,
                default => null,
            },
        ];
    }

    /**
     * Whether the piece's entry has been saved: for a new piece, that there
     * is one; for changes to an existing entry, that it was saved after
     * they were put into its form.
     */
    private function saved(Session $session, ?EntryContract $entry): bool
    {
        if ($entry === null) {
            return false;
        }

        if ($session->source === null) {
            return true;
        }

        $modified = $entry->lastModified();

        return $session->appliedAt !== null && $modified !== null && $modified->getTimestamp() >= Carbon::parse($session->appliedAt)->getTimestamp();
    }

    /**
     * The entry a session's draft became. A draft put into a publish form is
     * saved by the person, not by Ghostwriter, so where no entry is on
     * record one with the draft's title in the same collection, saved since
     * the piece was started, is taken to be it.
     */
    private function entryFor(Session $session, ?ContentType $type): ?EntryContract
    {
        if ($id = $session->source ?? $session->entryId) {
            return Entry::find($id);
        }

        if (! $type || $session->draft === null) {
            return null;
        }

        $title = mb_strtolower($session->title());
        $started = $session->createdAt ? Carbon::parse($session->createdAt)->getTimestamp() : null;

        return Entry::query()->where('collection', $type->collection)->get()
            ->first(fn (EntryContract $entry) => mb_strtolower(trim((string) $entry->get('title'))) === $title
                // An older entry that happens to share the title is not this piece.
                && ($started === null || ($modified = $entry->lastModified()) === null || $modified->getTimestamp() >= $started));
    }

    private function stage(Session $session, ?EntryContract $entry): string
    {
        return match (true) {
            $session->status === Session::FAILED => 'failed',
            $session->status === Session::WORKING => 'working',
            $session->source !== null => match (true) {
                $this->saved($session, $entry) => $entry->published() ? 'published' : 'saved',
                $session->appliedAt !== null => 'changed',
                default => 'editing',
            },
            $entry !== null => $entry->published() ? 'published' : 'saved',
            $session->appliedAt !== null => 'in_form',
            $session->draft !== null => 'draft',
            default => 'interview',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Session $session): array
    {
        $shared = app(SessionRepository::class)->shared();
        $type = $this->types->find($session->type);
        $problem = null;
        $words = 0;
        $preview = [];

        if ($session->draft !== null) {
            try {
                $draft = Draft::parse($session->draft);
                $words = $draft->wordCount();

                if ($blueprint = $type?->forSession($session)->statamicBlueprint()) {
                    $preview = $this->preview->render($draft->data, $this->reader->read($blueprint));
                }
            } catch (InvalidArgumentException $exception) {
                $problem = $exception->getMessage();
            }
        }

        return [
            'id' => $session->id,
            'editing' => $session->source !== null,
            // Whether the writer has asked something and is waiting for an answer.
            'waiting_on_you' => $session->status === Session::IDLE
                && ($last = end($session->messages)) !== false
                && $last['role'] === 'assistant'
                && ($last['asks'] ?? ($session->draft === null && $session->source === null)),
            'type' => $type?->forQuestionnaire(),
            'title' => $session->title(),
            'status' => $session->status,
            'error' => $session->error,
            // A failed turn can be run again when the message it was answering is the last one.
            'can_retry' => $session->status === Session::FAILED && ($last = end($session->messages)) !== false && $last['role'] === 'user',
            // Put into a publish form already; using it again replaces that.
            'applied' => $session->appliedAt !== null,
            // No worker has picked the turn up after a while: say so.
            'queue_waiting' => $session->status === Session::WORKING ? app(Waiting::class)->notice('session:'.$session->id) : null,
            // Ghostwriter's replies, rendered as the markdown they are
            // written in, with any HTML in them escaped.
            // Each person's message says who sent it, when the conversation
            // is shared; one with no sender is the starter's.
            'messages' => array_map(fn (array $message) => $message['role'] === 'assistant'
                ? $message + ['html' => $this->markdown()->convert((string) $message['content'])->getContent()]
                : $message + [
                    'mine' => ($by = $message['by'] ?? $session->userId) === null || $by === $this->me(),
                    'from' => $shared ? self::name($message['by'] ?? $session->userId) : null,
                ], $session->messages),
            ...$this->people($session),
            'draft' => $session->draft,
            'draft_problem' => $problem,
            'preview' => $preview,
            'words' => $words,
            'usage' => $session->usage,
            'images' => $this->images($session, $type),
            'image_tools' => ['generate' => $this->images->configured(), 'search' => $this->stock->sources()],
        ];
    }

    /**
     * Who started a piece and who last did something to it, when
     * conversations are shared; and who is waiting on Ghostwriter for it
     * now, when that is someone else.
     *
     * @return array{started_by: ?string, touched_by: ?string, waiting_on: ?string}
     */
    private function people(Session $session): array
    {
        $me = $this->me();
        $shared = app(SessionRepository::class)->shared();
        $touched = $session->touchedBy ?? $session->userId;
        $who = fn (?string $id) => $id !== null && $id === $me ? 'you' : self::name($id);

        return [
            'started_by' => $shared && $session->userId !== null ? $who($session->userId) : null,
            'touched_by' => $shared && $touched !== null && $touched !== $session->userId ? $who($touched) : null,
            'waiting_on' => $session->status === Session::WORKING && $session->runBy !== null && $session->runBy !== $me ? self::name($session->runBy) : null,
        ];
    }

    /**
     * A person's name as the Control Panel shows it, or "Someone" for a
     * user who has gone.
     */
    public static function name(?string $userId): string
    {
        $user = $userId !== null ? User::find($userId) : null;

        return $user ? (string) ($user->name() ?: $user->email()) : 'Someone';
    }

    private function me(): ?string
    {
        $id = User::current()?->id();

        return $id === null ? null : (string) $id;
    }

    private function markdown(): GithubFlavoredMarkdownConverter
    {
        return $this->markdown ??= new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /**
     * The draft's image fields, with the image chosen for each so far.
     *
     * @return array<int, array<string, mixed>>
     */
    private function images(Session $session, ?ContentType $type): array
    {
        if (! $type || (! $this->images->configured() && $this->stock->sources() === [])) {
            return [];
        }

        return collect($this->images->slots($session, $type->forSession($session)))
            ->map(fn (array $slot) => [
                'key' => $slot['key'],
                'label' => $slot['label'],
                'references' => count($slot['references']),
                'status' => $session->images[$slot['key']]['status'] ?? 'empty',
                'url' => $session->images[$slot['key']]['url'] ?? null,
                'error' => $session->images[$slot['key']]['error'] ?? null,
                'credit' => $session->images[$slot['key']]['credit'] ?? null,
                'query' => $session->images[$slot['key']]['query'] ?? null,
                'options' => $session->images[$slot['key']]['options'] ?? [],
                'judged' => (bool) ($session->images[$slot['key']]['judged'] ?? false),
                'none_fit' => (bool) ($session->images[$slot['key']]['none_fit'] ?? false),
                'with_references' => (bool) ($session->images[$slot['key']]['with_references'] ?? false),
                'direction' => $session->images[$slot['key']]['direction'] ?? '',
            ])
            ->values()
            ->all();
    }
}
