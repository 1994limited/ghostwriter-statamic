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

    /** Ghostwriter's mark as the pack's 14-grid stroke icon, in currentColor. */
    public const ICON = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path d="M2.5 12.5V6a4.5 4.5 0 0 1 9 0v4l-2.5 2.5Z" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.5 10H9v2.5" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"></path><circle cx="5.4" cy="6.25" r=".75" fill="currentColor"></circle><circle cx="8.6" cy="6.25" r=".75" fill="currentColor"></circle></svg>';

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
     * Whether a new entry's empty image fields get a striped placeholder.
     */
    public function placeholderImages(): bool
    {
        $raw = Addon::get(self::ADDON)?->settings()->raw() ?? [];

        return array_key_exists('placeholder_images', $raw) ? (bool) $raw['placeholder_images'] : (bool) config('ghostwriter.images.placeholders', true);
    }

    /**
     * Whether collections are checked for kinds of content by themselves.
     */
    public function suggestsKinds(): bool
    {
        // The settings screen fills in its own default, so only a value
        // actually saved there overrides the config.
        $raw = Addon::get(self::ADDON)?->settings()->raw() ?? [];

        return array_key_exists('suggest_kinds', $raw) ? (bool) $raw['suggest_kinds'] : (bool) config('ghostwriter.suggest_kinds', true);
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
