<?php

namespace NineteenNinetyFour\Ghostwriter\Http;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\Waiting;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefThread;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Progress;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Record;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionAccess;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\DraftPreview;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Settings;
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
        $type = $this->types->find($session->kind);
        $collection = $type ? TypeRepository::collectionOf($type) : null;

        $entry = $this->entryFor($session, $type);

        // Done with once its entry is saved: the draft has become an entry,
        // or the changes put into an existing one's form have been saved.
        // Put into the form and not saved is not done (E6).
        $progress = Progress::of($session, $entry ? Record::saved($entry->published(), $entry->lastModified()) : Record::none(), $this->options());

        return [
            'id' => $session->id,
            'title' => $session->title(),
            'type' => $type?->title ?? $session->kind,
            'collection' => $collection?->title(),
            'status' => $session->status,
            'has_draft' => $session->draft !== null,
            'stage' => $progress->stage,
            'finished' => $progress->finished,
            'entry_url' => $entry?->editUrl(),
            // Shared pieces are deleted by their starter or a manager only.
            'delete_url' => $this->access()->canDelete($session, self::viewer()) ? cp_route('ghostwriter.sessions.destroy', $session->id) : null,
            'updated_at' => Carbon::parse($session->updatedAt)->diffForHumans(),
            ...$this->people($session),
            // Sessions are resumed where they were started: on the entry
            // being edited, or on the collection's create screen.
            'url' => match (true) {
                $session->source !== null && ($entry = Entry::find((string) $session->source)) => $entry->editUrl().'?ghostwriter='.$session->id,
                $collection !== null => $collection->createEntryUrl().'?ghostwriter='.$session->id,
                default => null,
            },
        ];
    }

    /**
     * The entry a session's draft became. A draft put into a publish form is
     * saved by the person, not by Ghostwriter, so where no entry is on
     * record one with the draft's title in the same collection, saved since
     * the piece was started, is taken to be it.
     */
    private function entryFor(Session $session, ?ContentType $type): ?EntryContract
    {
        if (($id = $session->source ?? $session->recordId) !== null) {
            return Entry::find((string) $id);
        }

        if (! $type || $session->draft === null) {
            return null;
        }

        $title = mb_strtolower($session->title());
        $started = $session->createdAt ? Carbon::parse($session->createdAt)->getTimestamp() : null;

        return Entry::query()->where('collection', $type->group)->get()
            ->first(fn (EntryContract $entry) => mb_strtolower(trim((string) $entry->get('title'))) === $title
                // An older entry that happens to share the title is not this piece.
                && ($started === null || ($modified = $entry->lastModified()) === null || $modified->getTimestamp() >= $started));
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Session $session): array
    {
        $shared = $this->options()->shared;
        $type = $this->types->find($session->kind);
        $problem = null;
        $words = 0;
        $preview = [];

        if ($session->draft !== null) {
            try {
                $draft = Draft::parse($session->draft);
                $words = $draft->wordCount();

                if ($type && ($blueprint = TypeRepository::blueprintOf($type->forSession($session)))) {
                    $preview = $this->preview->render($draft->data, $this->reader->read($blueprint));
                }
            } catch (InvalidArgumentException $exception) {
                $problem = $exception->getMessage();
            }
        }

        $stage = BriefThread::stage($session);
        $card = BriefThread::card($session);

        return [
            'id' => $session->id,
            'editing' => $session->isEditing(),
            // Where the piece has got to: details, filling, proposed (the
            // brief card waits to be checked), writing, questions, drafting.
            'stage' => $stage->value,
            // The brief card, from the latest one, with the person's changes.
            'brief' => $card ? $card->toArray() + ['open' => $card->open(), 'agreed' => BriefThread::agreed($session)] : null,
            // A piece from before the brief card: its brief, as text.
            'brief_text' => $card ? null : BriefThread::text($session),
            // Whether the writer has asked something and is waiting for an answer.
            'waiting_on_you' => $stage->agreed()
                && $session->status === Session::IDLE
                && ($last = $session->lastMessage()) !== null
                && ($last['role'] ?? null) === 'assistant'
                && ($last['asks'] ?? ($session->draft === null && ! $session->isEditing())),
            'type' => $type ? TypeRepository::forQuestionnaire($type) : null,
            'title' => $session->title(),
            'status' => $session->status,
            'error' => $session->error,
            // A failed turn can be run again when the message it was answering is the last one.
            'can_retry' => $session->canRetry(),
            // Put into a publish form already; using it again replaces that.
            'applied' => $session->appliedAt !== null,
            // No worker has picked the turn up after a while: say so.
            'queue_waiting' => $session->isWorking() ? app(Waiting::class)->notice('session:'.$session->id, app(Settings::class)->workerCommand()) : null,
            // Ghostwriter's replies, rendered as the markdown they are
            // written in, with any HTML in them escaped.
            // Each person's message says who sent it, when the conversation
            // is shared; one with no sender is the starter's.
            // Only the messages to show (BriefThread::visible()), each with
            // its place in the whole conversation and its brief step: the
            // brief card is drawn from `brief`.
            'messages' => array_map(fn (int $index, array $message) => ['index' => $index, 'step' => BriefThread::step($message)] + ($message['role'] === 'assistant'
                ? $message + ['html' => $this->markdown()->convert((string) $message['content'])->getContent()]
                : $message + [
                    'mine' => ($by = self::id($message['by'] ?? $session->startedBy)) === null || $by === $this->me(),
                    'from' => $shared ? self::name(self::id($message['by'] ?? $session->startedBy)) : null,
                ]), array_keys($visible = BriefThread::visible($session)), $visible),
            // When the latest message was sent, for the timer while it is answered.
            'since' => $session->lastMessage()['at'] ?? null,
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
        $shared = $this->options()->shared;
        $started = self::id($session->startedBy);
        $touched = self::id($session->touchedBy) ?? $started;
        $waiting = self::id($session->waitingOn(self::viewer()));
        $who = fn (?string $id) => $id !== null && $id === $me ? 'you' : self::name($id);

        return [
            'started_by' => $shared && $started !== null ? $who($started) : null,
            'touched_by' => $shared && $touched !== null && $touched !== $started ? $who($touched) : null,
            'waiting_on' => $waiting !== null ? self::name($waiting) : null,
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

    /**
     * The person asking, as core's rules see them: whether they manage
     * Ghostwriter (its settings permission) and whether they are a super
     * user, who sees every piece.
     */
    public static function viewer(): Viewer
    {
        $user = User::current();

        if (! $user) {
            return Viewer::nobody();
        }

        return new Viewer((string) $user->id(), manager: (bool) $user->can('edit '.Settings::ADDON.' settings'), admin: (bool) $user->isSuper());
    }

    private function me(): ?string
    {
        return self::id(User::current()?->id());
    }

    /**
     * A user reference as text, as Statamic keeps them.
     */
    private static function id(int|string|null $id): ?string
    {
        return $id === null || $id === '' ? null : (string) $id;
    }

    private function access(): SessionAccess
    {
        return new SessionAccess($this->options());
    }

    private function options(): DomainOptions
    {
        return app(DomainOptions::class);
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
