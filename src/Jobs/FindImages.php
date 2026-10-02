<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequests;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\ModelInputGuard;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Images\FieldImages;
use NineteenNinetyFour\Ghostwriter\Images\FieldSlot;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use Throwable;

/**
 * Searches the photo libraries for one field on a form and ranks what it
 * finds against the page and the pictures already in that place.
 */
class FindImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $request) {}

    public function subject(): ?string
    {
        return 'image:'.$this->request;
    }

    public function handle(ImageRequests $requests, FieldImages $images, StockLibraries $libraries): void
    {
        $this->allowTimeToFinish();

        $request = $requests->find($this->request);

        if (! $request) {
            return;
        }

        try {
            $slot = FieldSlot::find(...(array) ($request->details['slot'] ?? [])) ?? throw new \InvalidArgumentException('That image field is no longer on the page.');
            $source = (string) ($request->details['source'] ?? StockLibraries::FREE);
            $finder = $source === StockLibraries::FREE ? null : new PhotoFinder(
                $libraries->search($source, (bool) ($request->details['editorial'] ?? false)),
                app(Providers::class),
                app(PromptLibrary::class),
                Log::channel(config('ghostwriter.log_channel')),
                guard: app(ModelInputGuard::class),
            );
            $results = $images->find($slot, $request->terms, $finder);

            if ($results->terms === []) {
                throw new \InvalidArgumentException('Type what the picture should show: there is nothing on the page to go by yet.');
            }

            $offered = ImageStudio::offered($results);

            // What the cards show: the library's short name, and whether it
            // is a paid photo (put in as a preview, not used outright).
            $offered['options'] = array_map(fn (array $option) => $option + [
                'source_label' => $libraries->shortLabel((string) $option['source']),
                'paid' => ! ($option['offer']['free'] ?? true),
            ], $offered['options']);
            $offered['paid_libraries'] = array_values(array_unique(array_map(fn (array $option) => $libraries->shortLabel((string) $option['source']), array_filter($offered['options'], fn (array $option) => $option['paid']))));

            $requests->succeed($this->request, $offered + ['terms' => $results->terms]);
        } catch (Throwable $exception) {
            report($exception);

            $requests->fail($this->request, $exception->getMessage());
        }
    }
}
