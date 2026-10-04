<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Seo\Outline;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftLayouts;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftValues;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Images\ContainerAssetSink;
use NineteenNinetyFour\Ghostwriter\Preview\PagePreview;
use NineteenNinetyFour\Ghostwriter\Seo\HeadingProfiles;
use NineteenNinetyFour\Ghostwriter\Storage\FileRenderProfiles;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * The Preview tab: the session's draft rendered through the site's own
 * templates, as "Use this draft" would fill the form, and never saved
 * (design §7). Answers where to load the page and the map the panel's
 * locator reads it with.
 */
class PreviewController
{
    public function __construct(
        private SessionGuard $sessions,
        private TypeRepository $types,
    ) {}

    public function store(Request $request, string $session, DraftValues $values, PagePreview $preview, DraftLayouts $layouts): JsonResponse
    {
        abort_unless(config('ghostwriter.preview.enabled', true), 404);

        $session = $this->session($session);
        $type = $this->type($session->kind)->forSession($session);

        $validated = $request->validate([
            'blueprint' => ['nullable', 'string', 'max:200'],
            'values' => ['nullable', 'array'],
            'site' => ['nullable', 'string', 'max:100'],
            // A layout other than the chosen one, for its card's thumbnail.
            'plan' => ['nullable', 'string', 'max:20'],
        ]);

        abort_if($session->draft === null, 422, 'There is no draft yet.');

        try {
            Draft::parse($session->draft);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        $blueprint = SessionController::blueprintFor($type, $validated['blueprint'] ?? null);

        // The chosen layout, as apply would use it; or the card's own.
        $draft = $layouts->draft($session, $type, $blueprint, $validated['plan'] ?? null);
        $arranged = $draft->raw !== Draft::parse($session->draft)->raw;
        $original = $session->source !== null ? Entry::find((string) $session->source) : null;

        // Seen only by someone who could save the entry by hand, as apply.
        SessionController::ensureCanSave($original, $blueprint);

        $form = (array) ($validated['values'] ?? []);
        $site = $original?->locale() ?? (Site::get((string) ($validated['site'] ?? '')) ?? Site::selected())->handle();

        $started = microtime(true);

        // Exactly apply's data, except that a placeholder image is only
        // used where the container has one: the preview saves nothing.
        $built = $values->build($session, $type, $draft, $blueprint, $form, $original, new ContainerAssetSink(create: false));

        $render = $preview->render($session, $draft, $blueprint, $built, $original, $form, $site, $request->getSchemeAndHttpHost(), $arranged);

        return response()->json($render + ['ms' => (int) round((microtime(true) - $started) * 1000)]);
    }

    /**
     * The rendered page's headings (locator.js outline()), posted by the
     * panel after each render: recorded on the collection's render profile
     * (two renders must agree to change it). `changed` asks the panel to
     * render once more, as the draft's headings are now fitted to a
     * different template.
     */
    public function outline(Request $request, string $session, FileRenderProfiles $profiles, HeadingProfiles $headings): JsonResponse
    {
        abort_unless(config('ghostwriter.preview.enabled', true), 404);

        $session = $this->session($session);
        $type = $this->type($session->kind)->forSession($session);

        $validated = $request->validate([
            'blueprint' => ['nullable', 'string', 'max:200'],
            'site' => ['nullable', 'string', 'max:100'],
            'outline' => ['present', 'array', 'max:'.Outline::MAX],
            'outline.*' => ['array'],
        ]);

        $blueprint = SessionController::blueprintFor($type, $validated['blueprint'] ?? null);
        $original = $session->source !== null ? Entry::find((string) $session->source) : null;
        $site = $original?->locale() ?? (Site::get((string) ($validated['site'] ?? '')) ?? Site::selected())->handle();
        $key = FileRenderProfiles::key($type->group, $blueprint->handle(), $site);

        [$profile, $changed] = (new SeoPass(logger: Log::channel(config('ghostwriter.log_channel'))))
            ->observe($profiles, $key, Outline::fromArray($validated['outline']), HeadingProfiles::label($type->group));

        $headings->forget();

        return response()->json(['changed' => $changed, 'h1' => $profile->h1->value, 'renders' => $profile->renders]);
    }

    private function type(string $handle): ContentType
    {
        $type = $this->types->find($handle);

        abort_unless($type && $this->types->enabled($type->group), 404);

        return $type;
    }

    private function session(string $id): Session
    {
        try {
            return $this->sessions->find($id, Presenter::viewer());
        } catch (NotFound) {
            abort(404);
        } catch (NotAllowed) {
            abort(403);
        }
    }
}
