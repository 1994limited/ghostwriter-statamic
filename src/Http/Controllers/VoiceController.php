<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Content\ContentScanner;
use NineteenNinetyFour\Ghostwriter\Jobs\GenerateVoiceGuide;
use NineteenNinetyFour\Ghostwriter\Jobs\RefineVoiceGuide;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceState;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;

class VoiceController
{
    public function __construct(private VoiceGuide $guide, private VoiceState $state, private Studio $studio) {}

    public function show(ContentScanner $scanner): Response
    {
        $blueprint = $this->blueprint();

        return Inertia::render('ghostwriter::Voice', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => $blueprint->fields()->addValues(['document' => $this->guide->get()])->preProcess()->meta()->all(),
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

        $this->state->update(['status' => VoiceState::WORKING, 'error' => null, 'task' => 'scan']);

        GenerateVoiceGuide::start($validated['collections'] ?? null);

        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['document' => ['required', 'string', 'max:60000']]);

        $this->guide->save($validated['document']);

        return response()->json($this->payload());
    }

    public function refine(Request $request): JsonResponse
    {
        $this->ensureReady();

        abort_unless($this->guide->exists(), 422, 'Generate a voice guide before refining it.');

        $validated = $request->validate(['message' => ['required', 'string', 'max:4000']]);

        $this->state->addMessage('user', $validated['message']);
        $this->state->update(['status' => VoiceState::WORKING, 'error' => null, 'task' => 'refine']);

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
        return $this->state->get() + [
            'document' => $this->guide->get(),
            'exists' => $this->guide->exists(),
            'updated_at' => $this->guide->updatedAt()?->diffForHumans(),
        ];
    }

    private function ensureReady(): void
    {
        abort_unless($this->studio->configured(), 422, 'No API key is set for the '.$this->studio->provider().' provider.');
        abort_if($this->state->get()['status'] === VoiceState::WORKING, 409, 'Ghostwriter is still working on the last request.');
    }
}
