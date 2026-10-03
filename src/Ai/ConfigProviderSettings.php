<?php

namespace NineteenNinetyFour\Ghostwriter\Ai;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ModelTiers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderSettings;
use NineteenNinetyFour\Ghostwriter\Settings;

/**
 * The provider choices for Ghostwriter Core: the settings screen first,
 * then config/ghostwriter.php. Read on every call, so a change on the
 * settings screen applies at once.
 */
class ConfigProviderSettings implements ModelTiers, ProviderSettings
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

    /**
     * With OpenRouter, the model chosen for a tier ("writing" or "quick"):
     * config first, then the settings screen. Null leaves it to the
     * provider's model setting, then OpenRouter's default for the tier.
     */
    public function tierModel(string $provider, string $tier): ?string
    {
        return $provider === 'openrouter' ? $this->settings->openRouterModel($tier) : null;
    }

    public function anthropicFallbacks(): bool
    {
        return (bool) config('ghostwriter.anthropic_fallbacks', true);
    }
}
