<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Planning\IdeaRepository;
use NineteenNinetyFour\Ghostwriter\Planning\PlanState;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;
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

    public function handle(Studio $studio, TypeRepository $types, IdeaRepository $ideas, VoiceGuide $guide, PlanState $state): void
    {
        $this->allowTimeToFinish();

        try {
            $suggested = $studio->suggestIdeas($this->collections, $types, $ideas->all()->values()->all(), $guide->get(), $this->steer);

            // Held for the person to look over; nothing joins the plan until
            // they say which.
            $state->update(['status' => PlanState::IDLE, 'error' => null, 'task' => null, 'pending' => array_values($suggested)]);
        } catch (Throwable $exception) {
            report($exception);

            $state->update(['status' => PlanState::FAILED, 'error' => $exception->getMessage(), 'task' => null]);
        }
    }
}
