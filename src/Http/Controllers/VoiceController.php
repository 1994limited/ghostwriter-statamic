<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Content\ContentScanner;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\Waiting;
use NineteenNinetyFour\Ghostwriter\Jobs\GenerateVoiceGuide;
use NineteenNinetyFour\Ghostwriter\Jobs\RefineVoiceGuide;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\WorkStates;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;

class VoiceController
{
    public function __construct(private GuideStore $guides, private WorkStates $states, private Studio $studio) {}

    public function show(ContentScanner $scanner): Response
    {
        $blueprint = $this->blueprint();

        return Inertia::render('ghostwriter::Voice', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => $blueprint->fields()->addValues(['document' => $this->guides->guide(Guide::VOICE)->body])->preProcess()->meta()->all(),
            'configured' => $this->studio->configured(),
            'provider' => $this->studio->provider(),
            'collections' => $scanner->collections(),
            'state' => $this->payload(),
            'urls' => [
                'index' => cp_route('ghostwriter.index'),
                'status' => cp_route('ghostwriter.voice.status'),
                'scan' => cp_route('ghostwriter.voice.scan'),
                'update' => cp_route('ghostwriter.voice.update'),
                'refine' => cp_route('ghostwriter.voice.refine'),
            ],
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function scan(Request $request): JsonResponse
    {
        $this->ensureReady();

        $validated = $request->validate([
            'collections' => ['nullable', 'array'],
            'collections.*' => ['string'],
        ]);

        $this->begin(fn (GuideState $state) => $state->begin('scan'));

        GenerateVoiceGuide::start($validated['collections'] ?? null);

        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['document' => ['required', 'string', 'max:60000']]);

        $this->guides->saveGuide(new Guide(Guide::VOICE, $validated['document']));

        return response()->json($this->payload());
    }

    public function refine(Request $request): JsonResponse
    {
        $this->ensureReady();

        abort_unless($this->guides->guide(Guide::VOICE)->exists(), 422, 'Generate a voice guide before refining it.');

        $validated = $request->validate(['message' => ['required', 'string', 'max:4000']]);

        $this->begin(function (GuideState $state) use ($validated) {
            $state->begin('refine');
            $state->addMessage('user', $validated['message']);
        });

        RefineVoiceGuide::start();

        return response()->json($this->payload());
    }

    /**
     * The guide is edited in Statamic's own markdown field, so it gets the
     * toolbar, preview and full-screen mode editors already know. Images and
     * assets are left off the toolbar: the guide is text.
     */
    private function blueprint(): Blueprint
    {
        return BlueprintFacade::makeFromFields([
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
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $state = $this->states->guide(Guide::VOICE);
        $guide = $this->guides->guide(Guide::VOICE);

        return $state->toArray() + [
            // No worker has picked the job up after a while: say so.
            'waiting' => $state->isWorking() ? app(Waiting::class)->notice('guide:voice', app(Settings::class)->workerCommand()) : null,
            'document' => $guide->body,
            'exists' => $guide->exists(),
            'updated_at' => $guide->updatedAt ? Carbon::instance($guide->updatedAt)->diffForHumans() : null,
        ];
    }

    private function ensureReady(): void
    {
        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');
        abort_if($this->states->guide(Guide::VOICE)->isWorking(), 409, 'Ghostwriter is still working on the last request.');
    }

    /**
     * Start a scan or a refinement; one at a time.
     *
     * @param  callable(GuideState): mixed  $start
     */
    private function begin(callable $start): void
    {
        try {
            $this->states->changeGuide(Guide::VOICE, $start);
        } catch (Conflict $conflict) {
            abort(409, $conflict->getMessage());
        }
    }
}
