<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewMarkers;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comment;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comments;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftComments;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftLayouts;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\ApplyComments;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;

/**
 * Comments on the draft in the Preview (design §9, decision 9). Pins not
 * sent yet are the editor's own, in their panel; **Apply N comments**
 * sends them as one message in the conversation, claiming the piece as
 * Send does, and one reviser call answers them in the background.
 * Resolving and Put back act on a comment's result in that answer. Every
 * answer is the session as the panel shows it.
 */
class CommentsController
{
    public function __construct(
        private SessionGuard $sessions,
        private Comments $comments,
        private DraftComments $drafts,
        private TypeRepository $types,
        private Presenter $presenter,
    ) {}

    /**
     * **Apply N comments**: each pin's words, and where it was made.
     */
    public function apply(Request $request, string $session, Studio $studio): JsonResponse
    {
        $session = $this->session($session);
        $type = $this->type($session);

        abort_if($session->draft === null, 422, 'There is no draft to comment on yet.');
        abort_unless($studio->configured(), 422, 'No API key is set for the '.$studio->provider().' provider.');

        $validated = $request->validate([
            'comments' => ['required', 'array', 'min:1', 'max:'.Comments::PER_APPLY],
            'comments.*.body' => ['required', 'string', 'max:'.Comment::MAX_BODY],
            'comments.*.kind' => ['required', Rule::in(['block', 'text', 'page'])],
            'comments.*.units' => ['nullable', 'array', 'max:200'],
            'comments.*.units.*' => ['string', 'max:40'],
            'comments.*.label' => ['nullable', 'string', 'max:200'],
            'comments.*.plan' => ['nullable', 'string', 'max:20'],
            'comments.*.path' => ['nullable', 'string', 'max:500'],
            'comments.*.plan_path' => ['nullable', 'string', 'max:200'],
            'comments.*.quote' => ['nullable', 'array'],
            'comments.*.quote.exact' => ['nullable', 'string', 'max:2000'],
            'comments.*.quote.prefix' => ['nullable', 'string', 'max:500'],
            'comments.*.quote.suffix' => ['nullable', 'string', 'max:500'],
        ], [
            'comments.required' => __('There are no comments to apply.'),
            'comments.min' => __('There are no comments to apply.'),
            'comments.max' => __('Apply at most :max comments at a time.'),
            'comments.*.body.required' => __('Write the comment first.'),
            'comments.*.body.max' => __('A comment can be at most :max characters.'),
            'comments.*.kind.required' => __('Say what the comment is on: a block, some words or the whole page.'),
            'comments.*.kind.in' => __('Say what the comment is on: a block, some words or the whole page.'),
            'comments.*.units.max' => __('That is too much of the page for one comment. Comment on the whole page instead.'),
            'comments.*.quote.exact.max' => __('That is a lot of words for one comment. Select fewer, or comment on the whole block.'),
        ]);

        $comments = [];
        $errors = [];

        foreach ($validated['comments'] as $i => $comment) {
            // Words copied from the preview never carry its invisible markers.
            $body = trim(str_replace("\r", '', PreviewMarkers::stripText((string) $comment['body'])));

            foreach (['exact', 'prefix', 'suffix'] as $part) {
                if (isset($comment['quote'][$part]) && is_string($comment['quote'][$part])) {
                    $comment['quote'][$part] = PreviewMarkers::stripText($comment['quote'][$part]);
                }
            }

            if ($body === '') {
                $errors["comments.{$i}.body"] = __('Write the comment first.');

                continue;
            }

            try {
                $comments[] = ['scope' => $this->drafts->scope($session, $type, $comment), 'body' => $body];
            } catch (InvalidArgumentException $exception) {
                $errors["comments.{$i}"] = __($exception->getMessage());
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $session = $this->guarded(function () use ($session, $comments) {
            $started = $this->comments->apply($session->id, $this->viewer(), $comments);

            ApplyComments::start($started->id);

            return $started;
        });

        return response()->json($this->presenter->detail($session));
    }

    /**
     * Resolve (or reopen) a comment Ghostwriter answered.
     */
    public function resolve(Request $request, string $session, int $answer, int $number): JsonResponse
    {
        $session = $this->session($session);
        $resolved = $request->boolean('resolved', true);

        $session = $this->guarded(fn () => $this->comments->resolve($session->id, $this->viewer(), $answer, $number, $resolved));

        return response()->json($this->presenter->detail($session));
    }

    /**
     * **Put it back**: a comment's change undone. No model.
     */
    public function putBack(string $session, int $answer, int $number, DraftLayouts $layouts): JsonResponse
    {
        $session = $this->session($session);
        $site = $layouts->safeContext($this->type($session)->forSession($session)) ?? abort(422, 'The collection this was written for no longer exists.');

        $session = $this->guarded(fn () => $this->comments->putBack($session->id, $this->viewer(), $answer, $number, $site));

        return response()->json($this->presenter->detail($session));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (Busy $busy) {
            abort(409, $busy->messageFor(fn (int|string $id) => Presenter::name((string) $id)));
        } catch (NotFound $notFound) {
            abort(404, $notFound->getMessage() ?: 'That comment has gone.');
        } catch (Refused $refused) {
            abort($refused->status(), $refused->getMessage());
        }
    }

    private function type(Session $session): ContentType
    {
        $type = $this->types->find($session->kind);

        abort_unless($type && $this->types->enabled($type->group), 404);

        return $type;
    }

    private function session(string $id): Session
    {
        try {
            return $this->sessions->find($id, $this->viewer());
        } catch (NotFound) {
            abort(404);
        } catch (NotAllowed) {
            abort(403);
        }
    }

    private function viewer(): Viewer
    {
        return Presenter::viewer();
    }
}
