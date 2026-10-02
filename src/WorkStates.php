<?php

namespace NineteenNinetyFour\Ghostwriter;

use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\Analysis;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanState;

/**
 * The screens' background work: looking for ideas, writing a guide,
 * suggesting kinds of content and learning one. Read through here, work
 * that stopped without saying so (a killed worker, a time limit) shows as
 * failed and can be started again (CRA-2); changes are made under a lock,
 * as a job and a request can both be at them.
 */
class WorkStates
{
    public function __construct(
        private Plan $plan,
        private GuideStore $guides,
        private KindStore $kinds,
        private Lock $lock,
        private DomainOptions $options,
    ) {}

    public function plan(): PlanState
    {
        $state = $this->plan->state();

        if ($state->isStale($this->options)) {
            $state = $this->plan->changeState(fn (PlanState $state) => $state->recoverIfStale($this->options));
        }

        return $state;
    }

    public function guide(string $kind): GuideState
    {
        $state = $this->guides->state($kind);

        return $state->isStale($this->options)
            ? $this->changeGuide($kind, fn (GuideState $state) => $state->recoverIfStale($this->options))
            : $state;
    }

    /**
     * @param  callable(GuideState): mixed  $change
     */
    public function changeGuide(string $kind, callable $change): GuideState
    {
        return $this->lock->run('guide:'.$kind, function () use ($kind, $change) {
            $state = $this->guides->state($kind);
            $change($state);
            $this->guides->saveState($kind, $state);

            return $state;
        });
    }

    public function suggestions(string $collection): KindSuggestions
    {
        $state = $this->kinds->suggestions($collection);

        return $state->isStale($this->options)
            ? $this->changeSuggestions($collection, fn (KindSuggestions $state) => $state->recoverIfStale($this->options))
            : $state;
    }

    /**
     * @param  callable(KindSuggestions): mixed  $change
     */
    public function changeSuggestions(string $collection, callable $change): KindSuggestions
    {
        return $this->lock->run('kinds', function () use ($collection, $change) {
            $state = $this->kinds->suggestions($collection);
            $change($state);
            $this->kinds->saveSuggestions($collection, $state);

            return $state;
        });
    }

    public function analysis(string $collection): Analysis
    {
        $state = $this->kinds->analysis($collection);

        return $state->isStale($this->options)
            ? $this->changeAnalysis($collection, fn (Analysis $state) => $state->recoverIfStale($this->options))
            : $state;
    }

    /**
     * @param  callable(Analysis): mixed  $change
     */
    public function changeAnalysis(string $collection, callable $change): Analysis
    {
        return $this->lock->run('types', function () use ($collection, $change) {
            $state = $this->kinds->analysis($collection);
            $change($state);
            $this->kinds->saveAnalysis($collection, $state);

            return $state;
        });
    }
}
