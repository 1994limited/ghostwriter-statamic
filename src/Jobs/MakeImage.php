<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Images\FieldImages;
use NineteenNinetyFour\Ghostwriter\Images\FieldSlot;
use NineteenNinetyFour\Ghostwriter\Images\ImageRequests;
use Throwable;

/**
 * Has a picture made for one field on a form, in the style of the pictures
 * already in that place, and keeps it to be looked at before it is used.
 */
class MakeImage implements ShouldQueue
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

        $data = $requests->find($this->request);

        if (! $data) {
            return;
        }

        try {
            $slot = FieldSlot::find(...$data['slot']) ?? throw new \InvalidArgumentException('That image field is no longer on the page.');
            $made = $images->make($slot, (string) ($data['direction'] ?? ''), $data['source'] ?? null);
            $extension = str_replace('jpeg', 'jpg', (string) substr((string) $made['mime'], 6)) ?: 'png';
            $file = $requests->file($this->request, $extension);

            File::put($file, $made['content']);

            $requests->update($this->request, ['status' => ImageRequests::DONE, 'file' => $file, 'mime' => $made['mime'], 'error' => null]);
        } catch (Throwable $exception) {
            report($exception);

            $requests->update($this->request, ['status' => ImageRequests::FAILED, 'error' => $exception->getMessage()]);
        }
    }
}
