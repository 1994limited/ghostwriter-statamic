<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Onboarding;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Stock\Ledger;
use NineteenNinetyFour\Ghostwriter\Suggest\SuggestEdits;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\WorkStates;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * The dashboard. Opening it never starts a model call: kinds are looked for
 * by themselves only on Get started, and here when someone asks.
 */
class DashboardController
{
    public function __invoke(Studio $studio, GuideStore $guides, TypeRepository $types, SessionGuard $sessions, Presenter $presenter, Settings $settings, WorkStates $states, PlanStore $plan): Response
    {
        $summaries = collect($sessions->visible(Presenter::viewer()))->map(fn ($session) => $presenter->summary($session))->values();
        $guide = $guides->guide(Guide::VOICE);
        $open = count(array_filter($plan->ideas(), fn (Idea $idea) => $idea->isOpen()));

        return Inertia::render('ghostwriter::Index', [
            'setup' => app(Onboarding::class)->progress() + ['url' => cp_route('ghostwriter.setup.show'), 'hide_url' => cp_route('ghostwriter.setup.hide')],
            'counts' => [
                'in_progress' => $summaries->reject(fn (array $summary) => $summary['finished'])->count(),
                'ideas' => $open,
            ],
            'configured' => $studio->configured(),
            'provider' => $studio->provider(),
            'settings_url' => $settings->urlForCurrentUser(),
            'voice' => [
                'exists' => $guide->exists(),
                'updated_at' => $guide->updatedAt ? Carbon::instance($guide->updatedAt)->diffForHumans() : null,
                'url' => cp_route('ghostwriter.voice.show'),
            ],
            'plan' => [
                'open' => $open,
                'url' => cp_route('ghostwriter.plan.show'),
            ],
            'imagery' => [
                'exists' => $guides->guide(Guide::IMAGERY)->exists(),
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
                'state' => $states->analysis($collection->handle())->toArray(),
                'kinds_url' => cp_route('ghostwriter.kinds.show', $collection->handle()),
                'suggest_url' => cp_route('ghostwriter.kinds.suggest', $collection->handle()),
                'learn_all_url' => cp_route('ghostwriter.kinds.learn_all', $collection->handle()),
                'suggested' => $this->suggested($states, $collection->handle()),
                'api_url' => cp_route('ghostwriter.collections.show', $collection->handle()),
                'analyse_url' => cp_route('ghostwriter.collections.analyse', $collection->handle()),
                'url' => $collection->createEntryUrl().'?ghostwriter=new',
            ])->values(),
            'sessions' => $summaries->take(30)->values(),
            'suggest_all_url' => cp_route('ghostwriter.kinds.suggest_all'),
            'stock' => app(Ledger::class)->overview(),
            'revisit' => $this->revisit(),
        ]);
    }

    /**
     * Content to revisit at a glance: pages worth a look, and the top few
     * with their first reason. Read from the list; no model.
     *
     * @return array<string, mixed>
     */
    private function revisit(): array
    {
        $site = Site::selected()?->handle() ?? Site::default()->handle();
        $rows = app(RevisitStore::class);
        $now = Carbon::now()->toDateTimeImmutable();
        $suggest = app(SuggestEdits::class);

        return [
            'worth' => (int) ($rows->stats($site, $now)['worth-a-look'] ?? 0),
            'top' => array_map(fn ($row) => [
                'title' => $row->title,
                'reason' => $row->reasons !== [] ? $suggest->text($row->reasons[0]->message()) : '',
            ], $rows->top($site, null, 3, 0, [], $now)),
            'url' => cp_route('ghostwriter.revisit.show'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function suggested(WorkStates $states, string $collection): array
    {
        $state = $states->suggestions($collection);

        return [
            'status' => $state->status,
            'error' => $state->error,
            'suggestions' => array_map(fn (array $suggestion) => $suggestion + [
                'titles' => array_values(array_filter(array_map(fn (string $id) => Entry::find($id)?->get('title'), (array) ($suggestion['examples'] ?? [])))),
                'learn_url' => cp_route('ghostwriter.kinds.learn', [$collection, $suggestion['id']]),
                'dismiss_url' => cp_route('ghostwriter.kinds.dismiss', [$collection, $suggestion['id']]),
            ], $state->suggestions),
        ];
    }
}
