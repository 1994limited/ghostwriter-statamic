<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NineteenNinetyFour\Ghostwriter\Stock\StandInComps;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Live Preview shows a stock preview's comp in place of its stand-in
 * (design §7.0, a spike). Only for a Live Preview request (Statamic's
 * preview token) by a signed-in user with Ghostwriter access: any `src` or
 * `srcset` address that ends in a stand-in's file name (a plain asset
 * address, or a Glide one, which ends in it too) is pointed at the comp
 * route, which itself only serves signed-in Control Panel users. Anyone
 * else, a shared preview link opened while signed out included, gets the
 * page untouched, stand-in and all. Such a response is never cached.
 */
class StockPreviewsInLivePreview
{
    public function __construct(private StandInComps $standIns) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('ghostwriter.stock.live_preview', true) || ! $request->isLivePreview()) {
            return $response;
        }

        if (! str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'html') || ! User::current()?->can('access ghostwriter')) {
            return $response;
        }

        try {
            $comps = $this->comps();
            $html = (string) $response->getContent();

            if ($comps === [] || $html === '') {
                return $response;
            }

            $rewritten = (string) preg_replace_callback('/\b(src|srcset|data-src|data-srcset)\s*=\s*(["\'])(.*?)\2/is', fn (array $m) => $m[1].'='.$m[2].$this->rewrite($m[3], $comps).$m[2], $html);

            if ($rewritten !== $html) {
                $response->setContent($rewritten);
                $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $response;
    }

    /**
     * Each address in an attribute (a srcset has several, with their
     * widths) whose path ends in a stand-in's file name, pointed at its comp.
     *
     * @param  array<string, string>  $comps
     */
    private function rewrite(string $value, array $comps): string
    {
        return (string) preg_replace_callback('#[^\s,"\']+#', function (array $m) use ($comps) {
            $path = (string) parse_url(html_entity_decode($m[0]), PHP_URL_PATH);

            return $comps[basename($path)] ?? $m[0];
        }, $value);
    }

    /**
     * Stand-ins' file names, and their comps' addresses: previews (and
     * licences not yet settled) whose comp is still held.
     *
     * @return array<string, string>
     */
    private function comps(): array
    {
        return array_map(fn (array $comp) => $comp['url'], $this->standIns->all());
    }
}
