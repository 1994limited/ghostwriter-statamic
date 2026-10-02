<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Images\ImageryGuide;
use NineteenNinetyFour\Ghostwriter\Images\ImageryState;
use NineteenNinetyFour\Ghostwriter\Jobs\GenerateImageryGuide;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Entry;

/**
 * The image style guide's screen. It is the voice guide's screen with other
 * words on it: generate from what the site has, then correct by hand.
 */
class ImageryController
{
    public function __construct(private ImageryGuide $guide, private ImageryState $state, private Studio $studio, private TypeRepository $types) {}

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
            'meta' => $blueprint->fields()->addValues(['document' => $this->guide->get()])->preProcess()->meta()->all(),
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
        abort_if($this->state->get()['status'] === ImageryState::WORKING, 409, 'Ghostwriter is still working on the last request.');

        $validated = $request->validate([
            'collections' => ['required', 'array', 'min:1'],
            'collections.*' => ['string'],
        ]);

        $collections = array_values(array_filter($validated['collections'], fn (string $handle) => $this->types->enabled($handle)));

        $this->state->update(['status' => ImageryState::WORKING, 'error' => null, 'task' => 'scan']);

        GenerateImageryGuide::start($collections);

        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['document' => ['required', 'string', 'max:60000']]);

        $this->guide->save($validated['document']);

        return response()->json($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return $this->state->get() + [
            'document' => $this->guide->get(),
            'exists' => $this->guide->exists(),
            'updated_at' => $this->guide->updatedAt()?->diffForHumans(),
        ];
    }
}
