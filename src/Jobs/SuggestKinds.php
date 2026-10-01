<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Types\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
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

    public function handle(Studio $studio, TypeRepository $types, KindSuggestions $kinds): void
    {
        $this->allowTimeToFinish();

        foreach ($this->collections as $handle) {
            $collection = Collection::findByHandle($handle);

            if (! $collection) {
                continue;
            }

            try {
                $suggestions = $studio->suggestKinds($collection, $types, $kinds);

                $kinds->store($handle, $suggestions, Entry::query()->where('collection', $handle)->where('published', true)->count());
            } catch (Throwable $exception) {
                report($exception);

                $kinds->update($handle, ['status' => KindSuggestions::FAILED, 'error' => $exception->getMessage()]);
            }
        }
    }
}
