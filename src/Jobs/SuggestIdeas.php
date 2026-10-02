<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Throwable;

/**
 * Reads what the site has and finds ideas for what it is missing, for the
 * person to choose from.
 */
class SuggestIdeas implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array<int, string>  $collections
     */
    public function __construct(public array $collections, public string $steer = '') {}

    public function handle(Studio $studio, TypeRepository $types, PlanStore $ideas, GuideStore $guides, Plan $plan): void
    {
        $this->allowTimeToFinish();

        try {
            $suggested = $studio->suggestIdeas($this->collections, $types, $ideas->ideas(), $guides->guide(Guide::VOICE)->body, $this->steer);

            // Held for the person to look over, beside any batch still
            // waiting; nothing joins the plan until they say which.
            $plan->receive($suggested);
        } catch (Throwable $exception) {
            report($exception);

            $plan->failed($exception->getMessage());
        }
    }
}
