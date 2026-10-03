<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Stock\CompStore;
use NineteenNinetyFour\Ghostwriter\Stock\DemoLibrary;
use NineteenNinetyFour\Ghostwriter\Stock\Licensing;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use NineteenNinetyFour\Ghostwriter\Stock\StockPresenter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stock photos in the Control Panel: the libraries' connections, and the
 * demo library's pictures.
 */
class StockController
{
    public function __construct(
        private StockLibraries $libraries,
        private Settings $settings,
        private StockImages $images,
        private CompStore $comps,
        private StockPresenter $presenter,
        private Licensing $licensing,
    ) {}

    /**
     * A preview's comp: the library's watermarked photo, served only to
     * signed-in Control Panel users with Ghostwriter access (the route's
     * middleware), never cached and never indexed. Where the library's
     * terms allow nothing to be stored, its own preview address instead.
     */
    public function comp(string $id): Response
    {
        $image = $this->images->find($id) ?? abort(404);
        $comp = $image->comp() ?? abort(404, 'This preview has expired.');
        $headers = ['Cache-Control' => 'private, no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow'];

        if (! $this->comps->isFile($comp)) {
            abort_unless(str_starts_with($comp, 'https://'), 404);

            return redirect()->away($comp)->withHeaders($headers);
        }

        $file = $this->comps->get($comp) ?? abort(404, 'This preview has expired.');

        return response($file['content'], 200, ['Content-Type' => $file['mime']] + $headers);
    }

    /**
     * One record, as the badge, the asset editor and the ledger screen show it.
     */
    public function show(string $id): JsonResponse
    {
        return response()->json($this->presenter->summary($this->record($id)));
    }

    /**
     * The live records of the assets a form holds (`container::path`),
     * for the badges on its assets fields. Assets the ledger doesn't know
     * are left out.
     */
    public function assets(Request $request): JsonResponse
    {
        $found = [];

        foreach (array_slice(array_filter((array) $request->input('assets', []), 'is_string'), 0, 100) as $key) {
            [$container, $path] = array_pad(explode('::', $key, 2), 2, '');
            $image = $container !== '' && $path !== '' ? $this->images->store()->forAsset(AssetRef::statamic($container, $path)) : null;

            if ($image !== null) {
                $found[$key] = $this->presenter->summary($image);
            }
        }

        return response()->json(['assets' => (object) $found]);
    }

    /**
     * The confirm step of License & replace: options, cost, notices, credit.
     */
    public function quotes(string $id): JsonResponse
    {
        $this->mayLicense();

        try {
            return response()->json($this->licensing->confirm($this->record($id)));
        } catch (NotConnected $lost) {
            return response()->json(['message' => $lost->getMessage(), 'connect_url' => $this->settings->urlForCurrentUser()], 422);
        } catch (PhotoUnavailable $exception) {
            abort(422, $exception->getMessage());
        }
    }

    /**
     * License & replace, with the option the person confirmed.
     */
    public function license(Request $request, string $id): JsonResponse
    {
        $this->mayLicense();
        $option = (string) $request->validate(['option' => ['required', 'string', 'max:200']])['option'];

        try {
            $result = $this->licensing->license($this->record($id), $option);
        } catch (PhotoUnavailable $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['message'],
            'notice' => $result['notice'] ?? null,
            'quote' => $result['quote'] ?? null,
            // The connection was lost: "Connect again" goes to the settings, for those who may.
            'connect_url' => ($result['connect'] ?? false) ? $this->settings->urlForCurrentUser() : null,
            'stock' => $this->presenter->summary($result['record']),
        ], $result['ok'] ? 200 : 422);
    }

    /**
     * "Download again and replace": a licence already bought, its file put in place.
     */
    public function replace(string $id): JsonResponse
    {
        $this->mayLicense();

        try {
            $result = $this->licensing->replaceAgain($this->record($id));
        } catch (PhotoUnavailable $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json(['ok' => $result['ok'], 'message' => $result['message'], 'stock' => $this->presenter->summary($result['record'])], $result['ok'] ? 200 : 422);
    }

    /**
     * "Request licence", from someone who may not license.
     */
    public function request(string $id): JsonResponse
    {
        $image = $this->record($id);
        abort_unless($image->is(StockImage::PREVIEW) || $image->is(StockImage::FAILED), 422, __('This image doesn\'t need a licence.'));

        return response()->json(['message' => __('Licence requested. A manager will see it at the top of the Stock images previews.'), 'stock' => $this->presenter->summary($this->licensing->request($image))]);
    }

    /**
     * "Refresh preview": the comp again, once, after its period ended.
     */
    public function refresh(string $id): JsonResponse
    {
        try {
            $image = $this->licensing->refresh($this->record($id));
        } catch (Conflict|PhotoUnavailable $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json(['message' => __('Preview refreshed.'), 'stock' => $this->presenter->summary($image)]);
    }

    private function record(string $id): StockImage
    {
        return $this->images->find($id) ?? abort(404);
    }

    private function mayLicense(): void
    {
        abort_unless(StockPresenter::canLicense(), 403, __('Licensing stock images needs the "License stock images" permission. Ask a manager to license it.'));
    }

    /**
     * "Check connection" on the settings screen: who the library is
     * connected as and what the account can still buy. Managers only.
     */
    public function check(string $library): JsonResponse
    {
        abort_unless($this->settings->canChange(), 403);

        return response()->json($this->libraries->check($library));
    }

    /**
     * A demo photo's thumbnail: drawn, watermarked, private to the Control Panel.
     */
    public function demoThumb(string $id): Response
    {
        $library = $this->libraries->configured()[DemoLibrary::ID] ?? abort(404);

        try {
            $file = $library->preview($id)->file ?? abort(404);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        return response($file->content, 200, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, max-age=3600', 'X-Robots-Tag' => 'noindex']);
    }
}
