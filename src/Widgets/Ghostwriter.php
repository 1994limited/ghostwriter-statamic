<?php

namespace NineteenNinetyFour\Ghostwriter\Widgets;

use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Onboarding;
use NineteenNinetyFour\Ghostwriter\Planning\IdeaRepository;
use NineteenNinetyFour\Ghostwriter\Sessions\SessionRepository;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\User;
use Statamic\Widgets\VueComponent;
use Statamic\Widgets\Widget;

/**
 * Ghostwriter on the Control Panel dashboard: how set-up stands, what is in
 * progress, what is waiting on the plan, and a way to start writing.
 *
 * Add to config/statamic/cp.php: ['type' => 'ghostwriter', 'limit' => 5].
 */
class Ghostwriter extends Widget
{
    public function component()
    {
        if (! User::current()?->can('access ghostwriter')) {
            return null;
        }

        $presenter = app(Presenter::class);
        $limit = max(1, min(20, (int) $this->config('limit', 5)));
        $inProgress = app(SessionRepository::class)->visibleTo(User::current())
            ->map(fn ($session) => $presenter->summary($session))
            ->reject(fn (array $summary) => $summary['finished'])
            ->values();

        return VueComponent::render('ghostwriter-widget', [
            'title' => $this->config('title', 'Ghostwriter'),
            'inProgress' => $inProgress->take($limit)->all(),
            'moreInProgress' => max(0, $inProgress->count() - $limit),
            'planOpen' => app(IdeaRepository::class)->all()->where('status', IdeaRepository::OPEN)->count(),
            'setup' => app(Onboarding::class)->progress(),
            'configured' => app(Studio::class)->configured(),
            'collections' => app(TypeRepository::class)->collections()->map(fn ($collection) => [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'url' => $collection->createEntryUrl().'?ghostwriter=new',
            ])->values()->all(),
            'urls' => [
                'index' => cp_route('ghostwriter.index'),
                'setup' => cp_route('ghostwriter.setup.show'),
                'plan' => cp_route('ghostwriter.plan.show'),
            ],
        ]);
    }
}
