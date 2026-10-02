<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequests;
use NineteenNinetyFour\Ghostwriter\Images\FieldImages;
use NineteenNinetyFour\Ghostwriter\Images\FieldSlot;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
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

    public function handle(ImageRequests $requests, FieldImages $images): void
    {
        $this->allowTimeToFinish();

        $request = $requests->find($this->request);

        if (! $request) {
            return;
        }

        try {
            $slot = FieldSlot::find(...(array) ($request->details['slot'] ?? [])) ?? throw new \InvalidArgumentException('That image field is no longer on the page.');
            $results = $images->find($slot, $request->terms);

            if ($results->terms === []) {
                throw new \InvalidArgumentException('Type what the picture should show: there is nothing on the page to go by yet.');
            }

            $requests->succeed($this->request, ImageStudio::offered($results) + ['terms' => $results->terms]);
        } catch (Throwable $exception) {
            report($exception);

            $requests->fail($this->request, $exception->getMessage());
        }
    }
}
