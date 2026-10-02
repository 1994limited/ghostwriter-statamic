<?php

namespace NineteenNinetyFour\Ghostwriter\Ai;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;

/**
 * API keys, read from config/ghostwriter.php (and so from .env) every time
 * one is needed, and never stored. Paid photo libraries' keys and
 * secrets (Getty, Shutterstock) are under `stock.keys`.
 *
 * A key that a site set in config/ai.php, when Ghostwriter used the Laravel
 * AI SDK, is still found there. The photo libraries' keys live under
 * `images`, where they always have.
 */
class ConfigCredentials implements Credentials
{
    private const LIBRARIES = ['unsplash', 'pexels', 'pixabay'];

    /** Paid photo libraries' keys and secrets, under `stock.keys`. */
    private const PAID = ['getty', 'getty_secret', 'shutterstock', 'shutterstock_secret'];

    public function key(string $provider): ?string
    {
        $places = match (true) {
            in_array($provider, self::LIBRARIES, true) => ["ghostwriter.images.{$provider}_key"],
            in_array($provider, self::PAID, true) => ["ghostwriter.stock.keys.{$provider}"],
            default => ["ghostwriter.keys.{$provider}", "ai.providers.{$provider}.key"],
        };

        foreach ($places as $place) {
            $key = config($place);

            if (is_string($key) && trim($key) !== '') {
                return trim($key);
            }
        }

        return null;
    }
}
