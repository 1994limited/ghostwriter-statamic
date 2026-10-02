<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Stock\DemoLibrary;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stock photos in the Control Panel: the libraries' connections, and the
 * demo library's pictures.
 */
class StockController
{
    public function __construct(private StockLibraries $libraries, private Settings $settings) {}

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
