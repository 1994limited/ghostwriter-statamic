<?php

namespace NineteenNinetyFour\Ghostwriter;

use Statamic\Facades\Addon;

/**
 * Where Ghostwriter's options come from: the addon's settings screen in the
 * Control Panel first, then config/ghostwriter.php (and so .env), so a site
 * can fix a value in code and leave the rest to its editors.
 */
class Settings
{
    public const ADDON = '1994/ghostwriter-statamic';

    /** Ghostwriter's own mark, drawn to sit beside the Control Panel's icons. */
    public const ICON = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M2.5 12.75V6a4.5 4.5 0 0 1 9 0v6.75l-1.5-1.4-1.5 1.4-1.5-1.4-1.5 1.4-1.5-1.4-1.5 1.4Z"/><circle cx="5.4" cy="6.1" r=".75" fill="currentColor"/><circle cx="8.6" cy="6.1" r=".75" fill="currentColor"/></svg>';

    public function provider(): string
    {
        return (string) ($this->saved('provider') ?: config('ghostwriter.provider', 'anthropic'));
    }

    public function model(): ?string
    {
        return ($this->saved('model') ?: config('ghostwriter.model')) ?: null;
    }

    /**
     * The provider images are made with. Null means "whichever has a key".
     */
    public function imageProvider(): ?string
    {
        return ($this->saved('image_provider') ?: config('ghostwriter.images.provider')) ?: null;
    }

    public function imageModel(): ?string
    {
        return ($this->saved('image_model') ?: config('ghostwriter.images.model')) ?: null;
    }

    /**
     * Collections Ghostwriter writes for. Empty means all of them.
     *
     * @return array<int, string>
     */
    public function collections(): array
    {
        return $this->handles($this->saved('collections') ?: config('ghostwriter.collections', []));
    }

    /**
     * Collections read for the voice guide. Empty means all of them.
     *
     * @return array<int, string>
     */
    public function voiceCollections(): array
    {
        return $this->handles($this->saved('voice_collections') ?: config('ghostwriter.voice.collections', []));
    }

    public function url(): ?string
    {
        return Addon::get(self::ADDON)?->settingsUrl();
    }

    private function saved(string $key): mixed
    {
        return Addon::get(self::ADDON)?->settings()->get($key);
    }

    /**
     * @return array<int, string>
     */
    private function handles(mixed $value): array
    {
        return array_values(array_filter(array_map('strval', (array) $value)));
    }
}
