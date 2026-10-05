<?php

namespace NineteenNinetyFour\Ghostwriter\Ai;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderKeys;
use SensitiveParameter;
use Throwable;

/**
 * @deprecated Settings → Connections keeps the key in its own store now
 * (core's StoredProviderKeys over EncryptedCredentialStore); this only
 * reads the old file so LegacyCredentials can move it.
 *
 * Where a site kept the API key it got with "Connect with OpenRouter"
 * (core's ProviderKeys): encrypted with the app's key (Laravel's Crypt) in
 * `storage/ghostwriter/provider-keys.json`, beside Ghostwriter's other
 * working files. Never in content/, never shown or logged. A key that
 * can't be decrypted (the app key changed) reads as not connected. A key
 * in .env always wins over it (core's ConnectedCredentials).
 */
class EncryptedProviderKeys implements ProviderKeys
{
    public function get(string $provider): ?string
    {
        $sealed = $this->all()[$provider] ?? null;

        if (! is_string($sealed)) {
            return null;
        }

        try {
            $key = Crypt::decryptString($sealed);
        } catch (Throwable) {
            return null;
        }

        return trim($key) !== '' ? $key : null;
    }

    public function put(string $provider, #[SensitiveParameter] string $key): void
    {
        $all = $this->all();
        $all[$provider] = Crypt::encryptString($key);
        $this->write($all);
    }

    public function forget(string $provider): void
    {
        $all = $this->all();
        unset($all[$provider]);
        $this->write($all);
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $path = self::path();

        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) File::get($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $all
     */
    private function write(array $all): void
    {
        File::ensureDirectoryExists(dirname(self::path()));
        File::put(self::path(), (string) json_encode($all, JSON_PRETTY_PRINT));
        @chmod(self::path(), 0600);
    }

    public static function path(): string
    {
        return dirname((string) config('ghostwriter.sessions_path')).'/provider-keys.json';
    }
}
