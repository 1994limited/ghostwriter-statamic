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
     * @param  array<int, array{title: ?string, examples: array<int, string>}>  $kinds  Several kinds to learn one after another, as "Learn all" asks. Empty learns the one above.
     */
    public function __construct(public string $collection, public ?string $title = null, public array $examples = [], public array $kinds = []) {}

    public function handle(Studio $studio, TypeRepository $types, TypeState $state): void
    {
        $this->allowTimeToFinish();

        $kinds = $this->kinds ?: [['title' => $this->title, 'examples' => $this->examples]];
        $failed = [];

        foreach ($kinds as $kind) {
            try {
                $collection = Collection::findByHandle($this->collection)
                    ?? throw new InvalidArgumentException("The collection \"{$this->collection}\" does not exist.");

                $examples = array_values(array_filter((array) ($kind['examples'] ?? [])));

                // A type modelled on chosen entries uses their blueprint, which
                // matters on a collection with more than one.
                $blueprint = ($examples ? Entry::find($examples[0])?->blueprint() : null) ?? $collection->entryBlueprint();

                $types->save($studio->analyseCollection($collection, $blueprint, $kind['title'] ?? null, $examples));
            } catch (Throwable $exception) {
                report($exception);

                // One kind going wrong does not stop the rest.
                $failed[] = (($kind['title'] ?? null) ? "{$kind['title']}: " : '').$exception->getMessage();
            }
        }

        $state->set($this->collection, $failed ? TypeState::FAILED : TypeState::IDLE, $failed ? implode(' ', $failed) : null);
    }
}
