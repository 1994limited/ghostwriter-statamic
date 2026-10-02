<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Jobs\SuggestKinds;
use NineteenNinetyFour\Ghostwriter\Onboarding;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Types\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;

/**
 * Get started: a page of steps that take Ghostwriter from installed to
 * writing, each done from the page itself.
 */
class SetupController
{
    public function __construct(private Onboarding $onboarding, private Studio $studio, private Settings $settings) {}

    public function show(TypeRepository $types, KindSuggestions $suggestions): Response
    {
        $this->suggestDueKinds($types, $suggestions);

        return Inertia::render('ghostwriter::Setup', $this->payload() + [
            'urls' => [
                'index' => cp_route('ghostwriter.index'),
                'status' => cp_route('ghostwriter.setup.status'),
                'hide' => cp_route('ghostwriter.setup.hide'),
                'collections' => cp_route('ghostwriter.setup.collections'),
            ],
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /**
     * Hidden or shown for the whole site, so only by those who look after
     * Ghostwriter's settings.
     */
    public function hide(Request $request): JsonResponse
    {
        abort_unless($this->settings->canChange(), 403, 'Only someone who can change Ghostwriter’s settings can hide or show Get started.');

        $this->onboarding->hide($request->boolean('hidden', true));

        return response()->json(['hidden' => $this->onboarding->hidden()]);
    }

    /**
     * Step 2, done in place: where Ghostwriter writes and what it learns
     * the voice from.
     */
    public function collections(Request $request): JsonResponse
    {
        abort_unless($this->settings->canChange(), 403, 'Only someone who can change Ghostwriter’s settings can choose these.');

        $validated = $request->validate([
            'collections' => ['nullable', 'array'],
            'collections.*' => ['string'],
            'voice_collections' => ['nullable', 'array'],
            'voice_collections.*' => ['string'],
        ]);

        // None would mean every collection, which is not what unticking them all says.
        abort_if(array_key_exists('collections', $validated) && ($validated['collections'] ?? []) === [], 422, 'Choose at least one collection for Ghostwriter to write for.');
        abort_if(array_key_exists('voice_collections', $validated) && ($validated['voice_collections'] ?? []) === [], 422, 'Choose at least one collection to learn the voice from.');

        $this->onboarding->chooseCollections($validated['collections'] ?? null, $validated['voice_collections'] ?? null);

        return response()->json($this->payload());
    }

    /**
     * Collections due a look for kinds of content are looked over as Get
     * started opens, when "Suggest kinds of content" is on: the first time
     * each is seen, and again once enough has been published there since.
     * Only here: elsewhere, kinds are suggested when someone asks.
     */
    private function suggestDueKinds(TypeRepository $types, KindSuggestions $suggestions): void
    {
        if (! $this->settings->suggestsKinds() || ! $this->studio->configured()) {
            return;
        }

        $due = $types->collections()->filter(fn ($collection) => $suggestions->due($collection))->map->handle()->values()->all();

        if ($due === []) {
            return;
        }

        foreach ($due as $handle) {
            $suggestions->update($handle, ['status' => KindSuggestions::WORKING, 'error' => null]);
        }

        SuggestKinds::start($due);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'configured' => $this->studio->configured(),
            'provider' => $this->studio->provider(),
            'steps' => $this->onboarding->steps(),
            'details' => $this->onboarding->details(),
            'progress' => $this->onboarding->progress(),
        ];
    }
}
