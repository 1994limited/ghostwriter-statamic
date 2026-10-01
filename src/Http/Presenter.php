<?php

namespace NineteenNinetyFour\Ghostwriter\Http;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Drafts\Draft;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftPreview;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Images\LogoCard;
use NineteenNinetyFour\Ghostwriter\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * Shapes sessions for the Control Panel.
 */
class Presenter
{
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
            // Done with: the draft has become a saved entry, or the changes
            // to an existing one have been put into its form.
            'finished' => $session->status !== Session::WORKING && ($session->source ? $session->appliedAt !== null : $entry !== null),
            'entry_url' => $entry?->editUrl(),
            'delete_url' => cp_route('ghostwriter.sessions.destroy', $session->id),
            'updated_at' => Carbon::parse($session->updatedAt)->diffForHumans(),
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
     * The entry a session's draft became. A draft put into a publish form is
     * saved by the person, not by Ghostwriter, so where no entry is on
     * record one with the draft's title in the same collection is taken to
     * be it.
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

        return Entry::query()->where('collection', $type->collection)->get()
            ->first(fn (EntryContract $entry) => mb_strtolower(trim((string) $entry->get('title'))) === $title);
    }

    private function stage(Session $session, ?EntryContract $entry): string
    {
        return match (true) {
            $session->status === Session::FAILED => 'failed',
            $session->status === Session::WORKING => 'working',
            $session->source !== null => $session->appliedAt ? 'changed' : 'editing',
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
            'messages' => $session->messages,
            'draft' => $session->draft,
            'draft_problem' => $problem,
            'preview' => $preview,
            'words' => $words,
            'usage' => $session->usage,
            'images' => $this->images($session, $type),
            'image_tools' => ['generate' => $this->images->configured(), 'search' => $this->stock->sources(), 'logo_card' => LogoCard::available()],
        ];
    }

    /**
     * The draft's image fields, with the image chosen for each so far.
     *
     * @return array<int, array<string, mixed>>
     */
    private function images(Session $session, ?ContentType $type): array
    {
        if (! $type || (! $this->images->configured() && $this->stock->sources() === [] && ! LogoCard::available())) {
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
                'direction' => $session->images[$slot['key']]['direction'] ?? '',
            ])
            ->values()
            ->all();
    }
}
