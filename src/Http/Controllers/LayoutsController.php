<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftLayouts;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Jobs\RefreshLayouts;
use NineteenNinetyFour\Ghostwriter\Jobs\RetrySearchMeta;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;

/**
 * The layout cards, the extras and the Search section in the writing
 * panel. Choosing a layout, editing or deleting an extra and editing the
 * search title, description or address call no model; "Refresh layouts"
 * is one call to the layout planner, and the Search section's "Try again"
 * one `seo-editor` call, each in the background. Everything is stored on
 * the session, so it is everyone's on the piece.
 */
class LayoutsController
{
    private const BUSY = 'Ghostwriter is still working on the draft. Try again when it has finished.';

    public function __construct(
        private SessionGuard $sessions,
        private TypeRepository $types,
        private DraftLayouts $layouts,
        private Presenter $presenter,
    ) {}

    public function choose(Request $request, string $session): JsonResponse
    {
        $plan = $request->validate(['plan' => ['required', 'string', 'max:20']])['plan'];

        $session = $this->guarded(fn () => $this->sessions->edit($this->session($session)->id, $this->viewer(), function (Session $session) use ($plan) {
            try {
                $this->layouts->choose($session, $plan);
            } catch (InvalidArgumentException $exception) {
                abort(422, $exception->getMessage());
            }
        }, self::BUSY));

        return response()->json($this->presenter->detail($session));
    }

    public function refresh(string $session): JsonResponse
    {
        $session = $this->session($session);

        abort_if($session->draft === null, 422, 'There is no draft yet.');
        abort_if($session->isWorking(), 409, self::BUSY);
        abort_if(DraftLayouts::isPlanning($session->id), 409, 'Ghostwriter is already looking for other layouts.');

        DraftLayouts::planning($session->id);
        RefreshLayouts::start($session->id);

        return response()->json($this->presenter->detail($session));
    }

    public function editExtra(Request $request, string $session, string $item): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['present', 'nullable', 'string', 'max:5000'],
            'parts' => ['nullable', 'array'],
            'parts.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $session = $this->session($session);
        $type = $this->type($session);
        $parts = isset($validated['parts']) ? array_map(fn ($part) => trim((string) $part), $validated['parts']) : null;

        $session = $this->guarded(fn () => $this->sessions->edit($session->id, $this->viewer(), function (Session $session) use ($item, $validated, $parts, $type) {
            try {
                $this->layouts->editExtra($session, $item, trim((string) ($validated['text'] ?? '')), $parts, $type);
            } catch (InvalidArgumentException $exception) {
                abort(422, $exception->getMessage());
            }
        }, self::BUSY));

        return response()->json($this->presenter->detail($session));
    }

    public function deleteExtra(string $session, string $item): JsonResponse
    {
        $session = $this->session($session);
        $type = $this->type($session);

        $session = $this->guarded(fn () => $this->sessions->edit($session->id, $this->viewer(), function (Session $session) use ($item, $type) {
            try {
                $this->layouts->deleteExtra($session, $item, $type);
            } catch (InvalidArgumentException $exception) {
                abort(422, $exception->getMessage());
            }
        }, self::BUSY));

        return response()->json($this->presenter->detail($session));
    }

    /**
     * "Remove link" on a link Ghostwriter added (the Text tab's popover):
     * its words stay, the link goes, and the writer won't put it back. No
     * call.
     */
    public function removeLink(Request $request, string $session): JsonResponse
    {
        $href = $request->validate(['href' => ['required', 'string', 'max:2000']])['href'];
        $session = $this->session($session);
        $type = $this->type($session);

        abort_if(DraftLayouts::isChecking($session->id), 409, 'Ghostwriter is still checking the links.');

        $session = $this->guarded(fn () => $this->sessions->edit($session->id, $this->viewer(), function (Session $session) use ($href, $type) {
            try {
                $this->layouts->removeLink($session, $href, $type);
            } catch (InvalidArgumentException $exception) {
                abort(422, $exception->getMessage());
            }
        }, self::BUSY));

        return response()->json($this->presenter->detail($session));
    }

    /**
     * An edit in the Search section: the SEO title (empty: use the page
     * title), the description, or the address (empty: from the title).
     * Theirs from now on; nothing goes into the entry until "Use this
     * draft". No call.
     */
    public function editSearch(Request $request, string $session): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['title', 'description', 'slug'])],
            'text' => ['present', 'nullable', 'string', 'max:1000'],
        ]);

        $session = $this->session($session);
        $type = $this->type($session);

        $this->ensureSearchIdle($session);

        $session = $this->guarded(fn () => $this->sessions->edit($session->id, $this->viewer(), function (Session $session) use ($validated, $type) {
            try {
                $this->layouts->editMeta($session, $validated['role'], (string) ($validated['text'] ?? ''), $type);
            } catch (InvalidArgumentException $exception) {
                abort(422, $exception->getMessage());
            }
        }, self::BUSY));

        return response()->json($this->presenter->detail($session));
    }

    /**
     * "Use this" beside an SEO value of the entry's own that stays (`use`
     * false takes it back): the draft's text goes in on "Use this draft"
     * after all. No call.
     */
    public function useSearch(Request $request, string $session): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['title', 'description'])],
            'use' => ['sometimes', 'boolean'],
        ]);

        $session = $this->session($session);

        $this->ensureSearchIdle($session);

        $session = $this->guarded(fn () => $this->sessions->edit($session->id, $this->viewer(), function (Session $session) use ($validated) {
            $this->layouts->useMeta($session, $validated['role'], (bool) ($validated['use'] ?? true));
        }, self::BUSY));

        return response()->json($this->presenter->detail($session));
    }

    /**
     * "Try again" in the Search section: one call for another title and
     * description, in the background (RetrySearchMeta). The panel shows
     * "Writing another…" until it has finished.
     */
    public function retrySearch(string $session): JsonResponse
    {
        $session = $this->session($session);
        $this->type($session);

        abort_if($session->draft === null, 422, 'There is no draft yet.');
        abort_if($session->isWorking(), 409, self::BUSY);
        abort_if(DraftLayouts::isChecking($session->id), 409, 'Ghostwriter is still checking the draft.');
        abort_if(DraftLayouts::isWriting($session->id), 409, 'Ghostwriter is already writing another title and description.');

        DraftLayouts::writing($session->id);
        RetrySearchMeta::start($session->id);

        return response()->json($this->presenter->detail($session));
    }

    /** The Search section is edited only once the draft's first pass and any Try again have finished. */
    private function ensureSearchIdle(Session $session): void
    {
        abort_if(DraftLayouts::isChecking($session->id), 409, 'Ghostwriter is still checking the draft.');
        abort_if(DraftLayouts::isWriting($session->id), 409, 'Ghostwriter is writing another title and description.');
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
        } catch (NotFound) {
            abort(404);
        } catch (Refused $refused) {
            abort($refused->status(), $refused->getMessage());
        }
    }

    private function viewer(): Viewer
    {
        return Presenter::viewer();
    }
}
