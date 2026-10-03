<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Middleware;

use Closure;
use Facades\Statamic\CP\LivePreview;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Preview\PreviewEntryRepository;
use NineteenNinetyFour\Ghostwriter\Preview\PreviewPolicy;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Entries\EntryRepository;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\User;
use Statamic\Tokens\Handlers\LivePreview as LivePreviewHandler;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The front-end side of Ghostwriter's page preview. Only on a request
 * whose Live Preview token holds an item Ghostwriter made (it carries the
 * `live_preview.ghostwriter` supplement); every other request, Statamic's
 * own Live Preview included, passes straight through.
 *
 * - While the page renders, `Entry::find()` answers the previewed entry
 *   for its id (PreviewEntryRepository), so tags such as `collection:next`
 *   work for an entry that was never saved.
 * - The response gets the preview's policy headers (PreviewPolicy), added
 *   beside the site's own and Statamic's multisite `frame-ancestors`.
 * - A page that fails to render (an error, or a 404) is replaced by a
 *   small page saying so, which the panel reads to show its own message
 *   with Blocks still to hand. Admins also get the exception and where it
 *   was thrown. The error is still reported as usual.
 *
 * Pushed to the front of the `statamic.web` group, so it wraps Statamic's
 * token handling and sees the response after it.
 */
class GhostwriterPreviewResponse
{
    public const ERROR_META = 'ghostwriter-preview-error';

    public function handle(Request $request, Closure $next): Response
    {
        $item = $this->previewed($request);

        if (! $item) {
            return $next($request);
        }

        $bound = app(EntryRepository::class);
        app()->instance(EntryRepository::class, new PreviewEntryRepository($bound, $item));
        Entries::clearResolvedInstance(EntryRepository::class);

        try {
            $response = $next($request);
        } finally {
            app()->instance(EntryRepository::class, $bound);
            Entries::clearResolvedInstance(EntryRepository::class);
        }

        if ($response->getStatusCode() >= 400) {
            $response = $this->failed($response, $item);
        }

        return PreviewPolicy::fromConfig()->apply($response);
    }

    /**
     * The item a Ghostwriter preview token stands for, or null.
     */
    private function previewed(Request $request): ?Entry
    {
        try {
            $token = $request->statamicToken();

            if (! $token || $token->handler() !== LivePreviewHandler::class) {
                return null;
            }

            $item = LivePreview::item($token);
        } catch (Throwable) {
            return null;
        }

        if (! $item instanceof Entry || ! method_exists($item, 'getSupplement')) {
            return null;
        }

        $flag = $item->getSupplement('live_preview');

        return is_array($flag) && ($flag['ghostwriter'] ?? false) === true ? $item : null;
    }

    /**
     * The page the frame shows when the template couldn't render: a meta
     * tag the panel reads (same origin) and a line for anyone who opens it.
     */
    private function failed(Response $response, Entry $item): Response
    {
        $exception = property_exists($response, 'exception') ? $response->exception : null;
        $status = $response->getStatusCode();

        $detail = [
            'status' => $status,
            'message' => $exception
                ? Str::limit(trim(strtok(strip_tags($exception->getMessage()), "\n") ?: ''), 300)
                : ($status === 404 ? 'The page was not found.' : 'The server answered '.$status.'.'),
        ];

        // The exception's class and where it was thrown, for those who run the site.
        if ($exception && User::current()?->isSuper()) {
            $detail['exception'] = get_class($exception);
            $detail['file'] = Str::after($exception->getFile(), base_path().'/').':'.$exception->getLine();
            $detail['template'] = (string) rescue(fn () => $item->template(), '', false);
        }

        $json = htmlspecialchars((string) json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        $line = htmlspecialchars(__('The page template couldn’t render this draft.'), ENT_QUOTES);

        $page = new \Illuminate\Http\Response(
            '<!doctype html><html><head><meta charset="utf-8"><meta name="'.self::ERROR_META.'" content="'.$json.'"><title>'.$line.'</title></head>'
            .'<body style="font:15px/1.5 system-ui,sans-serif;margin:2rem;color:#444"><p>'.$line.'</p></body></html>',
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );

        foreach ($response->headers->all('content-security-policy') as $policy) {
            $page->headers->set('Content-Security-Policy', $policy, false);
        }

        return $page;
    }
}
