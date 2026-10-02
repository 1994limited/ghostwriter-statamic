<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Stock\CompStore;
use NineteenNinetyFour\Ghostwriter\Stock\DemoLibrary;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stock photos in the Control Panel: the libraries' connections, and the
 * demo library's pictures.
 */
class StockController
{
    public function __construct(private StockLibraries $libraries, private Settings $settings, private StockImages $images, private CompStore $comps) {}

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
