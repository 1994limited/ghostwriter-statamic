<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\WorkStates;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Throwable;

/**
 * Reads what each collection holds and suggests the kinds of content in it,
 * for a person to look over and learn the ones worth having.
 */
class SuggestKinds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, string>  $collections
     */
    public function __construct(public array $collections) {}

    public function handle(Studio $studio, TypeRepository $types, KindStore $kinds, WorkStates $states): void
    {
        $this->allowTimeToFinish();

        foreach ($this->collections as $handle) {
            $collection = Collection::findByHandle($handle);

            if (! $collection) {
                continue;
            }

            try {
                $suggestions = $studio->suggestKinds($collection, $types, $kinds->suggestions($handle)->dismissed);
                $published = Entry::query()->where('collection', $handle)->where('published', true)->count();

                $states->changeSuggestions($handle, fn (KindSuggestions $state) => $state->store($suggestions, $published));
            } catch (Throwable $exception) {
                report($exception);

                $states->changeSuggestions($handle, fn (KindSuggestions $state) => $state->fail($exception->getMessage()));
            }
        }
    }
}
