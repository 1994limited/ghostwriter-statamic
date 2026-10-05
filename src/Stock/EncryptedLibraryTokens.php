<?php

namespace NineteenNinetyFour\Ghostwriter\Stock;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Ports\LibraryTokens;
use Throwable;

/**
 * @deprecated Settings → Connections keeps tokens in its own store now
 * (core's StoredLibraryTokens over EncryptedCredentialStore); this only
 * reads the old file so LegacyCredentials can move it.
 *
 * Where a site kept its photo libraries' tokens (core's LibraryTokens):
 * the connected account's OAuth tokens, per site, encrypted with the
 * app's key (Laravel's Crypt) in `storage/ghostwriter/library-tokens.json`
 * beside Ghostwriter's other working files. Never in content/, never
 * shown: a token that can't be decrypted (the app key changed) reads as
 * not connected.
 */
class EncryptedLibraryTokens implements LibraryTokens
{
    public function get(string $library): ?TokenSet
    {
        $sealed = $this->all()[$library] ?? null;

        if (! is_string($sealed)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($sealed), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? TokenSet::fromArray($data) : null;
    }

    public function put(string $library, TokenSet $tokens): void
    {
        $all = $this->all();
        $all[$library] = Crypt::encryptString((string) json_encode($tokens->toArray()));
        $this->write($all);
    }

    public function forget(string $library): void
    {
        $all = $this->all();
        unset($all[$library]);
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
        return dirname((string) config('ghostwriter.sessions_path')).'/library-tokens.json';
    }
}
