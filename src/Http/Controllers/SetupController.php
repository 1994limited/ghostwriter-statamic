<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Onboarding;

/**
 * Get started: a page of steps that take Ghostwriter from installed to
 * writing, each done from the page itself.
 */
class SetupController
{
    public function __construct(private Onboarding $onboarding, private Studio $studio) {}

    public function show(): Response
    {
        return Inertia::render('ghostwriter::Setup', $this->payload() + [
            'urls' => [
                'index' => cp_route('ghostwriter.index'),
                'status' => cp_route('ghostwriter.setup.status'),
                'hide' => cp_route('ghostwriter.setup.hide'),
            ],
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function hide(Request $request): JsonResponse
    {
        $this->onboarding->hide($request->boolean('hidden', true));

        return response()->json(['hidden' => $this->onboarding->hidden()]);
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
