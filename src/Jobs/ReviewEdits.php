<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviews;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewStatus;
use NineteenNinetyFour\Ghostwriter\Suggest\SuggestEdits;
use Statamic\Facades\Entry;
use Throwable;

/**
 * Suggest edits' review: the `reviewer` call (one per part of a long
 * page, as the confirm said), validated by core and stored on the review,
 * which the guide is polling. The entry's values are the form's when the
 * person asked, unsaved typing included. Nothing is saved to the entry.
 */
class ReviewEdits implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<string, mixed>  $data  The entry's values as stored, from the form.
     */
    public function __construct(
        public string $reviewId,
        public string $entryId,
        public ?string $site,
        public array $data,
        public string $replyLanguage = 'en',
    ) {}

    public function subject(): ?string
    {
        return 'review:'.$this->reviewId;
    }

    public function handle(EditReviews $reviews, EditReviewStore $store, SuggestEdits $suggest): void
    {
        $this->allowTimeToFinish();

        // Once: a job handed out twice finds its review already taken.
        if ($store->find($this->reviewId)?->status !== ReviewStatus::Queued) {
            return;
        }

        try {
            $entry = Entry::find($this->entryId);

            if ($entry && $this->site !== null && $entry->locale() !== $this->site) {
                $entry = $entry->in($this->site);
            }

            if (! $entry) {
                throw new \RuntimeException('The entry has gone.');
            }

            $now = Carbon::now()->toDateTimeImmutable();
            $reviews->run($this->reviewId, $suggest->input($entry, $this->data, $now, $this->replyLanguage), $now);
        } catch (Throwable $exception) {
            Log::channel(config('ghostwriter.log_channel'))->warning('Ghostwriter: a review stopped: '.$exception->getMessage(), ['review' => $this->reviewId]);

            // Not left running: the guide says it failed, and the free
            // findings stand.
            $review = $store->find($this->reviewId);

            if ($review !== null && $review->status->isRunning()) {
                $review->status = ReviewStatus::Failed;
                $review->error = $exception->getMessage();
                $review->finishedAt = Carbon::now()->toAtomString();
                $store->save($review);
            }
        }
    }
}
