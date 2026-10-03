<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;

/**
 * The layout cards and the extras in the writing panel. Choosing a layout,
 * and editing or deleting an extra, call no model; "Refresh layouts" is one
 * call to the layout planner, in the background. Everything is stored on
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
