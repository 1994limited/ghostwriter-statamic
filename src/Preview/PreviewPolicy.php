<?php

namespace NineteenNinetyFour\Ghostwriter\Preview;

use Symfony\Component\HttpFoundation\Response;

/**
 * The headers on a Ghostwriter preview response (design §13).
 *
 * - A Content-Security-Policy that keeps the site's own scripts and blocks
 *   third-party ones (tag managers, analytics, chat widgets, pixels) and
 *   their beacons, stops forms posting, and lets only the site's own pages
 *   frame it. Hosts in `ghostwriter.preview.script_hosts` (a site's CDN)
 *   are allowed too.
 * - It is added beside any policy the response already has, never over
 *   it: on multisite, Statamic's Live Preview sends its own
 *   `frame-ancestors <site hosts>`, and a site may send a policy of its
 *   own. A browser enforces every policy it is sent, so the stricter rule
 *   always wins.
 * - No referrer (the token is in the address), no indexing, no caching,
 *   framing by the same origin only.
 */
final class PreviewPolicy
{
    /**
     * @param  array<int, string>  $scriptHosts
     */
    public function __construct(private array $scriptHosts = []) {}

    public static function fromConfig(): self
    {
        $hosts = config('ghostwriter.preview.script_hosts', []);

        if (is_string($hosts)) {
            $hosts = explode(',', $hosts);
        }

        return new self(array_values(array_filter(array_map(fn ($host) => trim((string) $host), (array) $hosts))));
    }

    public function contentSecurityPolicy(): string
    {
        // Only characters a source expression may hold, so a stray value
        // in config can't add a directive of its own.
        $hosts = array_filter($this->scriptHosts, fn (string $host) => preg_match('#^[A-Za-z0-9*.:/_-]+$#', $host) === 1);

        return implode('; ', [
            trim("script-src 'self' 'unsafe-inline' 'unsafe-eval' ".implode(' ', $hosts)),
            "connect-src 'self'",
            "form-action 'none'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
        ]);
    }

    public function apply(Response $response): Response
    {
        $headers = $response->headers;

        $existing = array_values(array_filter(array_map('strval', $headers->all('content-security-policy'))));
        $headers->set('Content-Security-Policy', [...$existing, $this->contentSecurityPolicy()]);

        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('Cache-Control', 'private, no-store, max-age=0');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-Ghostwriter-Preview', '1');

        return $response;
    }
}
