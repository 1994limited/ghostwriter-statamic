<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Images\ImageryGuide;
use NineteenNinetyFour\Ghostwriter\Planning\IdeaRepository;
use NineteenNinetyFour\Ghostwriter\Sessions\SessionRepository;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;
use Statamic\Facades\Entry;

class DashboardController
{
    public function __invoke(Studio $studio, VoiceGuide $guide, TypeRepository $types, SessionRepository $sessions, Presenter $presenter, Settings $settings): Response
    {
        return Inertia::render('ghostwriter::Index', [
            'configured' => $studio->configured(),
            'provider' => $studio->provider(),
            'settings_url' => $settings->url(),
            'voice' => [
                'exists' => $guide->exists(),
                'updated_at' => $guide->updatedAt()?->diffForHumans(),
                'url' => cp_route('ghostwriter.voice.show'),
            ],
            'plan' => [
                'open' => app(IdeaRepository::class)->all()->where('status', IdeaRepository::OPEN)->count(),
                'url' => cp_route('ghostwriter.plan.show'),
            ],
            'imagery' => [
                'exists' => app(ImageryGuide::class)->exists(),
                'url' => cp_route('ghostwriter.imagery.show'),
            ],
            'collections' => $types->collections()->map(fn ($collection) => [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'entries' => Entry::query()->where('collection', $collection->handle())->count(),
                'types' => $types->forCollection($collection->handle())->map(fn ($type) => [
                    'title' => $type->title,
                    'description' => $type->description,
                    'examples' => count($type->examples),
                    'edit_url' => cp_route('ghostwriter.types.edit', $type->handle),
                ])->values(),
                'api_url' => cp_route('ghostwriter.collections.show', $collection->handle()),
                'analyse_url' => cp_route('ghostwriter.collections.analyse', $collection->handle()),
                'url' => $collection->createEntryUrl().'?ghostwriter=new',
            ])->values(),
            'sessions' => $sessions->all()->take(30)->map(fn ($session) => $presenter->summary($session))->values(),
        ]);
    }
}
