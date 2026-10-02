<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequests;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\StoredFile;
use NineteenNinetyFour\Ghostwriter\Images\FieldImages;
use NineteenNinetyFour\Ghostwriter\Images\FieldSlot;
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

        $request = $requests->find($this->request);

        if (! $request) {
            return;
        }

        try {
            $slot = FieldSlot::find(...(array) ($request->details['slot'] ?? [])) ?? throw new \InvalidArgumentException('That image field is no longer on the page.');
            $source = $requests->file($this->request, StoredFile::SOURCE);
            $made = $images->make($slot, (string) ($request->details['direction'] ?? ''), $source ? Image::fromString($source->content) : null);
            $extension = str_replace('jpeg', 'jpg', (string) substr((string) $made['mime'], 6)) ?: 'png';

            $requests->putFile($this->request, StoredFile::MADE, new StoredFile($made['content'], $made['mime'], $extension));
            $requests->succeed($this->request);
        } catch (Throwable $exception) {
            report($exception);

            $requests->fail($this->request, $exception->getMessage());
        }
    }
}
