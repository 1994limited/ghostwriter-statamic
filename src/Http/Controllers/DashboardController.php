<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Images\ImageryGuide;
use NineteenNinetyFour\Ghostwriter\Onboarding;
use NineteenNinetyFour\Ghostwriter\Planning\IdeaRepository;
use NineteenNinetyFour\Ghostwriter\Sessions\SessionRepository;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Types\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\Types\TypeState;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * The dashboard. Opening it never starts a model call: kinds are looked for
 * by themselves only on Get started, and here when someone asks.
 */
class DashboardController
{
    public function __invoke(Studio $studio, VoiceGuide $guide, TypeRepository $types, SessionRepository $sessions, Presenter $presenter, Settings $settings, KindSuggestions $suggestions, TypeState $state): Response
    {
        $summaries = $sessions->visibleTo(User::current())->map(fn ($session) => $presenter->summary($session))->values();

        return Inertia::render('ghostwriter::Index', [
            'setup' => app(Onboarding::class)->progress() + ['url' => cp_route('ghostwriter.setup.show'), 'hide_url' => cp_route('ghostwriter.setup.hide')],
            'counts' => [
                'in_progress' => $summaries->reject(fn (array $summary) => $summary['finished'])->count(),
                'ideas' => app(IdeaRepository::class)->all()->where('status', IdeaRepository::OPEN)->count(),
            ],
            'configured' => $studio->configured(),
            'provider' => $studio->provider(),
            'settings_url' => $settings->urlForCurrentUser(),
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
                    'handle' => $type->handle,
                    'title' => $type->title,
                    'description' => $type->description,
                    'examples' => count($type->examples),
                    'edit_url' => cp_route('ghostwriter.types.edit', $type->handle),
                ])->values(),
                'state' => $state->get($collection->handle()),
                'kinds_url' => cp_route('ghostwriter.kinds.show', $collection->handle()),
                'suggest_url' => cp_route('ghostwriter.kinds.suggest', $collection->handle()),
                'learn_all_url' => cp_route('ghostwriter.kinds.learn_all', $collection->handle()),
                'suggested' => $this->suggested($suggestions, $collection->handle()),
                'api_url' => cp_route('ghostwriter.collections.show', $collection->handle()),
                'analyse_url' => cp_route('ghostwriter.collections.analyse', $collection->handle()),
                'url' => $collection->createEntryUrl().'?ghostwriter=new',
            ])->values(),
            'sessions' => $summaries->take(30)->values(),
            'suggest_all_url' => cp_route('ghostwriter.kinds.suggest_all'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function suggested(KindSuggestions $suggestions, string $collection): array
    {
        $state = $suggestions->get($collection);

        return [
            'status' => $state['status'],
            'error' => $state['error'],
            'suggestions' => array_map(fn (array $suggestion) => $suggestion + [
                'titles' => array_values(array_filter(array_map(fn (string $id) => Entry::find($id)?->get('title'), $suggestion['examples']))),
                'learn_url' => cp_route('ghostwriter.kinds.learn', [$collection, $suggestion['id']]),
                'dismiss_url' => cp_route('ghostwriter.kinds.dismiss', [$collection, $suggestion['id']]),
            ], $state['suggestions']),
        ];
    }
}
