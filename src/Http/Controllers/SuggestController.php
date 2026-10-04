<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\AnchorScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReview;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewAccess;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviews;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Suggestion;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionState;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\ReviewEdits;
use NineteenNinetyFour\Ghostwriter\Suggest\EntryChecks;
use NineteenNinetyFour\Ghostwriter\Suggest\StatamicAssetAlt;
use NineteenNinetyFour\Ghostwriter\Suggest\SuggestEdits;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Suggest edits, behind an existing entry's publish form:
 *
 * - guide: the free findings and the latest review, re-checked against
 *   the form's values, for the guide (no model, no save). Polled while a
 *   review runs.
 * - start: a review, once someone has seen the confirm with its call
 *   count; queued, never in the request.
 * - decide / undo: Accept (with the words that went in), Dismiss, It's
 *   still right, for everyone who sees the review. The change itself is
 *   already in the person's form; nothing here saves the entry.
 * - another: "Write another (uses Ghostwriter)", one small call.
 * - alt / unalt: "Save to the image", the one save: alt text on the
 *   asset, after its own confirm, and Undo writing the old text back.
 */
class SuggestController
{
    public function __construct(private SuggestEdits $suggest, private EditReviews $reviews, private EditReviewStore $store) {}

    public function guide(Request $request): JsonResponse
    {
        [$entry, $values] = $this->form($request);

        return response()->json($this->suggest->forGuide($entry, $values));
    }

    public function start(Request $request, Studio $studio): JsonResponse
    {
        [$entry, $values] = $this->form($request);

        abort_unless($studio->configured(), 422, __('Add an API key first: Suggest edits reads the page with your AI provider.'));

        $now = Carbon::now()->toDateTimeImmutable();

        try {
            $review = $this->reviews->start(EntryChecks::ref($entry), Presenter::viewer(), $now);
        } catch (Busy $busy) {
            abort(409, $busy->messageFor(fn ($id) => Presenter::name((string) $id)));
        }

        ReviewEdits::start($review->id, (string) $entry->id(), $entry->locale(), $this->suggest->data($entry, $values), (string) (User::current()?->preferredLocale() ?: config('app.locale', 'en')));

        return response()->json($this->suggest->forGuide($entry, $values));
    }

    public function decide(Request $request, string $review): JsonResponse
    {
        $request->validate([
            'decisions' => ['required', 'array', 'min:1', 'max:100'],
            'decisions.*.suggestion' => ['required', 'string', 'max:2000'],
            'decisions.*.state' => ['required', Rule::in(['accepted', 'dismissed', 'confirmed', 'open'])],
            'decisions.*.answer' => ['nullable', 'string', 'max:200'],
            'decisions.*.text' => ['nullable', 'string', 'max:5000'],
        ]);

        $found = $this->review($review, decide: true);
        $now = Carbon::now()->toDateTimeImmutable();
        $viewer = Presenter::viewer();
        $done = [];

        foreach ($request->input('decisions') as $decision) {
            $id = (string) $decision['suggestion'];

            // Asset alt text is saved by "Save to the image" only.
            if ($found->find($id)?->anchor->scope === AnchorScope::Asset && $decision['state'] === 'accepted') {
                continue;
            }

            try {
                if ($decision['state'] === 'open') {
                    $this->reviews->undo($found->id, $id, $viewer, $now);
                } else {
                    $this->reviews->decide($found->id, $id, SuggestionState::from($decision['state']), $viewer, $now, $decision['answer'] ?? null, $decision['text'] ?? null);
                }

                $done[] = $id;
            } catch (Conflict) {
                // Already decided, or no longer in the review: nothing to change.
            }
        }

        return response()->json(['decided' => $done, 'version' => $this->store->find($found->id)?->version]);
    }

    public function another(Request $request, string $review): JsonResponse
    {
        $request->validate(['suggestion' => ['required', 'string', 'max:2000']]);
        [$entry, $values] = $this->form($request);
        $found = $this->review($review, decide: true);
        $now = Carbon::now()->toDateTimeImmutable();

        try {
            $versions = $this->reviews->another($found->id, (string) $request->input('suggestion'), $this->suggest->input($entry, $this->suggest->data($entry, $values), $now), $now);
        } catch (Conflict|NotFound $conflict) {
            abort(422, $conflict->getMessage());
        } catch (ProviderException $failed) {
            Log::channel(config('ghostwriter.log_channel'))->warning('Ghostwriter: Write another failed: '.$failed->getMessage());
            abort(422, $failed->getMessage());
        } catch (InvalidArgumentException) {
            abort(422, __('Ghostwriter\'s answer couldn\'t be used. Try again, or write it yourself.'));
        }

        if ($versions === []) {
            abort(422, $this->suggest->text(new Message('suggest.review.another-none')));
        }

        return response()->json(['versions' => $versions]);
    }

    /**
     * "Save to the image": the alt text on the asset, now, and recorded
     * as done. The step's confirm has said it saves now, on every page
     * using the image.
     */
    public function alt(Request $request, string $review, StatamicAssetAlt $alt): JsonResponse
    {
        $request->validate(['suggestion' => ['required', 'string', 'max:2000'], 'alt' => ['required', 'string', 'max:300']]);

        $found = $this->review($review, decide: true);
        $suggestion = $this->assetSuggestion($found, (string) $request->input('suggestion'));
        $asset = $alt->asset($suggestion->anchor->asset);

        abort_unless($asset, 404, __('The image has gone.'));
        abort_unless(User::current()?->can('edit '.$asset->container()->handle().' assets'), 403, __('Ask someone who can edit assets to add it.'));

        $before = $alt->save($asset, (string) $request->input('alt'));
        $this->reviews->decide($found->id, $suggestion->id, SuggestionState::Accepted, Presenter::viewer(), Carbon::now()->toDateTimeImmutable(), text: trim((string) $request->input('alt')));

        return response()->json(['before' => $before, 'message' => __('Saved to :file.', ['file' => $asset->basename()])]);
    }

    /** Undo for "Save to the image": the alt text it had, written back. */
    public function unalt(Request $request, string $review, StatamicAssetAlt $alt): JsonResponse
    {
        $request->validate(['suggestion' => ['required', 'string', 'max:2000'], 'before' => ['nullable', 'string', 'max:300']]);

        $found = $this->review($review, decide: true);
        $suggestion = $this->assetSuggestion($found, (string) $request->input('suggestion'));
        $asset = $alt->asset($suggestion->anchor->asset);

        abort_unless($asset, 404, __('The image has gone.'));
        abort_unless(User::current()?->can('edit '.$asset->container()->handle().' assets'), 403);

        $alt->save($asset, (string) $request->input('before', ''));

        try {
            $this->reviews->undo($found->id, $suggestion->id, Presenter::viewer(), Carbon::now()->toDateTimeImmutable());
        } catch (Conflict) {
            // Nothing recorded to undo.
        }

        return response()->json(['message' => __('The alt text on :file is as it was.', ['file' => $asset->basename()])]);
    }

    private function assetSuggestion(EditReview $review, string $id): Suggestion
    {
        $suggestion = $review->find($id);

        abort_unless($suggestion && $suggestion->anchor->scope === AnchorScope::Asset && $suggestion->anchor->asset !== null, 404);

        return $suggestion;
    }

    /**
     * A review the person may see (and, for a decision, act on), of an
     * entry they may edit.
     */
    private function review(string $id, bool $decide = false): EditReview
    {
        $review = $this->store->find($id);

        abort_unless($review, 404);

        $entry = Entry::find((string) $review->entry->id);
        $canEdit = $entry && User::current()?->can('edit', $entry);
        $access = EditReviewAccess::from(app(DomainOptions::class));

        abort_unless($canEdit && $access->canSee($review, Presenter::viewer(), true), 403);
        abort_if($decide && ! $access->canDecide($review, Presenter::viewer(), true) && ! $review->status->isRunning(), 403);

        return $review;
    }

    /**
     * The entry being edited and its form's values.
     *
     * @return array{EntryContract, array<string, mixed>}
     */
    private function form(Request $request): array
    {
        $request->validate([
            'entry' => ['required', 'string'],
            'site' => ['nullable', 'string'],
            'values' => ['nullable', 'array'],
        ]);

        $entry = Entry::find((string) $request->input('entry'));

        if ($entry && $request->input('site') && $entry->locale() !== $request->input('site')) {
            $entry = $entry->in((string) $request->input('site')) ?? $entry;
        }

        abort_unless($entry && app(TypeRepository::class)->enabled((string) $entry->collectionHandle()), 404);
        abort_unless(User::current()?->can('edit', $entry), 403);

        $values = $request->input('values');

        return [$entry, is_array($values) ? $values : []];
    }
}
