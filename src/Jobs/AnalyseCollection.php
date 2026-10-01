<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\Types\TypeState;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Throwable;

/**
 * Learns a collection: reads its blueprint and existing entries and saves the
 * content type (questions and guidance) used when writing for it.
 */
class AnalyseCollection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array<int, string>  $examples
     */
    public function __construct(public string $collection, public ?string $title = null, public array $examples = []) {}

    public function handle(Studio $studio, TypeRepository $types, TypeState $state): void
    {
        $this->allowTimeToFinish();

        try {
            $collection = Collection::findByHandle($this->collection)
                ?? throw new InvalidArgumentException("The collection \"{$this->collection}\" does not exist.");

            // A type modelled on chosen entries uses their blueprint, which
            // matters on a collection with more than one.
            $blueprint = ($this->examples ? Entry::find($this->examples[0])?->blueprint() : null) ?? $collection->entryBlueprint();

            $types->save($studio->analyseCollection($collection, $blueprint, $this->title, $this->examples));

            $state->set($this->collection, TypeState::IDLE);
        } catch (Throwable $exception) {
            report($exception);

            $state->set($this->collection, TypeState::FAILED, $exception->getMessage());
        }
    }
}
