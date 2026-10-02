<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\Waiting;
use NineteenNinetyFour\Ghostwriter\Jobs\GenerateImageryGuide;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\WorkStates;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Entry;

/**
 * The image style guide's screen. It is the voice guide's screen with other
 * words on it: generate from what the site has, then correct by hand.
 */
class ImageryController
{
    public function __construct(private GuideStore $guides, private WorkStates $states, private Studio $studio, private TypeRepository $types) {}

    public function show(): Response
    {
        $blueprint = BlueprintFacade::makeFromFields([
            'document' => [
                'type' => 'markdown',
                'display' => 'Guide',
                'hide_display' => true,
                'buttons' => ['bold', 'italic', 'unorderedlist', 'orderedlist', 'quote', 'link'],
                'automatic_line_breaks' => false,
                'smartypants' => false,
                'antlers' => false,
            ],
        ]);

        // A failure stays on screen until the next run, so a job that failed
        // while nobody was looking still explains itself.
        $state = $this->payload();

        return Inertia::render('ghostwriter::Voice', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => $blueprint->fields()->addValues(['document' => $this->guides->guide(Guide::IMAGERY)->body])->preProcess()->meta()->all(),
            'configured' => $this->studio->configured(),
            'provider' => $this->studio->provider(),
            'collections' => $this->types->collections()->map(fn ($collection) => [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'entries' => Entry::query()->where('collection', $collection->handle())->where('published', true)->count(),
                'selected' => true,
            ])->values(),
            'state' => $state,
            'urls' => [
                'index' => cp_route('ghostwriter.index'),
                'status' => cp_route('ghostwriter.imagery.status'),
                'scan' => cp_route('ghostwriter.imagery.scan'),
                'update' => cp_route('ghostwriter.imagery.update'),
                'refine' => null,
            ],
            'labels' => [
                'title' => 'Image style',
                'saved' => 'Image style saved',
                'scanning' => 'Looking at the images your entries use and describing their style. This takes a minute or so.',
                'empty' => 'No guide yet. Choose which collections to look at, then generate it.',
                'read' => 'Ghostwriter looks at the images used by the newest published entries in each collection you tick, and writes a section for each.',
                'generate' => 'Generate from your images',
                'regenerate' => 'Look at the images again',
                'confirm' => 'Look at the images again and replace the current guide? Any edits you have made to it will be lost.',
                'first' => 'Describe the images',
                'again' => 'Look again and rewrite',
                'scanned' => 'Last written from :count image.|Last written from :count images.',
                'note' => 'Markdown, with a ## heading for each collection. Read whenever images are searched for, chosen or made.',
            ],
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function scan(Request $request): JsonResponse
    {
        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');
        abort_if($this->states->guide(Guide::IMAGERY)->isWorking(), 409, 'Ghostwriter is still working on the last request.');

        $validated = $request->validate([
            'collections' => ['required', 'array', 'min:1'],
            'collections.*' => ['string'],
        ]);

        $collections = array_values(array_filter($validated['collections'], fn (string $handle) => $this->types->enabled($handle)));

        try {
            $this->states->changeGuide(Guide::IMAGERY, fn (GuideState $state) => $state->begin('scan'));
        } catch (Conflict $conflict) {
            abort(409, $conflict->getMessage());
        }

        GenerateImageryGuide::start($collections);

        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['document' => ['required', 'string', 'max:60000']]);

        $this->guides->saveGuide(new Guide(Guide::IMAGERY, $validated['document']));

        return response()->json($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $state = $this->states->guide(Guide::IMAGERY);
        $guide = $this->guides->guide(Guide::IMAGERY);

        return $state->toArray() + [
            // No worker has picked the job up after a while: say so.
            'waiting' => $state->isWorking() ? app(Waiting::class)->notice('guide:imagery', app(Settings::class)->workerCommand()) : null,
            'document' => $guide->body,
            'exists' => $guide->exists(),
            'updated_at' => $guide->updatedAt ? Carbon::instance($guide->updatedAt)->diffForHumans() : null,
        ];
    }
}
