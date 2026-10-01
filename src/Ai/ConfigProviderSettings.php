<?php

namespace NineteenNinetyFour\Ghostwriter\Ai;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderSettings;
use NineteenNinetyFour\Ghostwriter\Settings;

/**
 * The provider choices for Ghostwriter Core: the settings screen first,
 * then config/ghostwriter.php. Read on every call, so a change on the
 * settings screen applies at once.
 */
class ConfigProviderSettings implements ProviderSettings
{
    public function __construct(private Settings $settings) {}

    public function textProvider(): string
    {
        return $this->settings->provider();
    }

    public function textModel(): ?string
    {
        return $this->settings->model();
    }

    public function imageProvider(): ?string
    {
        return $this->settings->imageProvider();
    }

    public function imageModel(): ?string
    {
        return $this->settings->imageModel();
    }

    public function timeout(): int
    {
        return $this->settings->timeout();
    }

    public function baseUrl(string $provider): ?string
    {
        $url = config("ghostwriter.base_urls.{$provider}");

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    public function anthropicFallbacks(): bool
    {
        return (bool) config('ghostwriter.anthropic_fallbacks', true);
    }
}
