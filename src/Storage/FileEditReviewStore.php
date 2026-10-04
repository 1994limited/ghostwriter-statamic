<?php

namespace NineteenNinetyFour\Ghostwriter\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReview;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewStatus;

/**
 * Suggest edits' reviews, one JSON file each in
 * `ghostwriter.edit_reviews_path` (beside the sessions by default), with a
 * pointer per entry listing its reviews newest first: the page's history.
 * Like the sessions, it needs no database.
 *
 * The version check is core's optimistic concurrency: EditReviews makes
 * every change under the entry's lock, so a save that finds a newer
 * version on disk is someone else's, and is refused.
 */
class FileEditReviewStore implements EditReviewStore
{
    use JsonFiles;

    public function find(string $id): ?EditReview
    {
        if (! self::isId($id)) {
            return null;
        }

        $data = $this->readJson($this->path($id));

        return is_array($data) && isset($data['id']) ? EditReview::fromArray($data) : null;
    }

    public function latestFor(EntryRef $entry): ?EditReview
    {
        foreach ($this->ids($entry) as $id) {
            if ($review = $this->find($id)) {
                return $review;
            }
        }

        return null;
    }

    public function history(EntryRef $entry, int $limit = 50): array
    {
        $reviews = [];

        foreach ($this->ids($entry) as $id) {
            if (count($reviews) >= $limit) {
                break;
            }

            if ($review = $this->find($id)) {
                $reviews[] = $review;
            }
        }

        return $reviews;
    }

    public function save(EditReview $review): EditReview
    {
        $stored = $this->find($review->id);

        if ($stored !== null && $stored->version !== $review->version) {
            throw new Conflict('Someone else changed this review first.');
        }

        $review->version++;
        $this->writeJson($this->path($review->id), $review->toArray());

        if ($stored === null) {
            $ids = $this->ids($review->entry);
            array_unshift($ids, $review->id);
            $this->writeJson($this->pointer($review->entry), ['entry' => $review->entry->key(), 'reviews' => array_values(array_unique($ids))]);
        }

        return $review;
    }

    public function dueToExpire(DateTimeInterface $now, int $limit = 100): array
    {
        $due = [];
        $at = DateTimeImmutable::createFromInterface($now);

        foreach (glob($this->directory().'/*.json') ?: [] as $path) {
            if (count($due) >= $limit) {
                break;
            }

            $data = $this->readJson($path);

            if (! is_array($data) || ! is_string($data['expiresAt'] ?? null)) {
                continue;
            }

            $review = EditReview::fromArray($data);

            if (! $review->status->isRunning() && in_array($review->status, [ReviewStatus::Ready, ReviewStatus::Failed], true) && $review->isDue($at)) {
                $due[] = $review->id;
            }
        }

        return $due;
    }

    public function delete(string $id): void
    {
        $review = $this->find($id);

        if ($review === null) {
            return;
        }

        File::delete($this->path($id));

        $left = array_values(array_filter($this->ids($review->entry), fn (string $other) => $other !== $id));

        if ($left === []) {
            File::delete($this->pointer($review->entry));

            return;
        }

        $this->writeJson($this->pointer($review->entry), ['entry' => $review->entry->key(), 'reviews' => $left]);
    }

    /**
     * An entry's review IDs, newest first.
     *
     * @return list<string>
     */
    private function ids(EntryRef $entry): array
    {
        $data = $this->readJson($this->pointer($entry));

        return array_values(array_filter(is_array($data['reviews'] ?? null) ? $data['reviews'] : [], fn ($id) => is_string($id) && self::isId($id)));
    }

    /** Core's ULIDs: nothing else is ours to look up. */
    private static function isId(string $id): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id) === 1;
    }

    private function directory(): string
    {
        return (string) (config('ghostwriter.edit_reviews_path') ?: $this->stateDirectory().'/edit-reviews');
    }

    private function path(string $id): string
    {
        return $this->directory().'/'.$id.'.json';
    }

    private function pointer(EntryRef $entry): string
    {
        return $this->directory().'/entries/'.sha1($entry->key()).'.json';
    }
}
