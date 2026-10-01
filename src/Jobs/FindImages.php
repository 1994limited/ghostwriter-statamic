<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Images\FieldImages;
use NineteenNinetyFour\Ghostwriter\Images\FieldSlot;
use NineteenNinetyFour\Ghostwriter\Images\ImageRequests;
use Throwable;

/**
 * Searches the photo libraries for one field on a form and ranks what it
 * finds against the pictures already in that place.
 */
class FindImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $request) {}

    public function handle(ImageRequests $requests, FieldImages $images): void
    {
        $this->allowTimeToFinish();

        $data = $requests->find($this->request);

        if (! $data) {
            return;
        }

        try {
            $slot = FieldSlot::find(...$data['slot']) ?? throw new \InvalidArgumentException('That image field is no longer on the page.');
            $terms = $data['terms'] ?: $images->searchTerms($slot);

            if ($terms === []) {
                throw new \InvalidArgumentException('Type what the picture should show: there is nothing on the page to go by yet.');
            }

            $requests->update($this->request, ['status' => ImageRequests::DONE, 'terms' => $terms, 'options' => $images->shortlist($slot, $terms), 'error' => null]);
        } catch (Throwable $exception) {
            report($exception);

            $requests->update($this->request, ['status' => ImageRequests::FAILED, 'error' => $exception->getMessage()]);
        }
    }
}
