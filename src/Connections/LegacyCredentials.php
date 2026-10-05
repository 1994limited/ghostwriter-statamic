<?php

namespace NineteenNinetyFour\Ghostwriter\Connections;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Ai\EncryptedProviderKeys;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Connections;
use NineteenNinetyFour\Ghostwriter\Core\Connections\StoredLibraryTokens;
use NineteenNinetyFour\Ghostwriter\Stock\EncryptedLibraryTokens;
use Throwable;

/**
 * Moves what Ghostwriter kept before Connections into its store, once: the
 * key from Connect with OpenRouter (storage/ghostwriter/provider-keys.json)
 * and paid libraries' account tokens (library-tokens.json). Each old file
 * is removed once everything in it has a place; nothing already set up is
 * replaced. Runs as the store is first used, so no command is needed.
 */
class LegacyCredentials
{
    public static function adopt(Connections $connections): void
    {
        $keys = EncryptedProviderKeys::path();

        if (is_file($keys)) {
            try {
                $old = new EncryptedProviderKeys;

                foreach (array_keys((array) json_decode((string) File::get($keys), true)) as $provider) {
                    $key = $old->get((string) $provider);

                    if ($key !== null) {
                        $connections->adopt((string) $provider, ['key' => $key], 'connect');
                    }
                }

                File::delete($keys);
            } catch (Throwable) {
                // Tried again next time.
            }
        }

        $tokens = EncryptedLibraryTokens::path();

        if (is_file($tokens)) {
            try {
                $old = new EncryptedLibraryTokens;
                $store = new StoredLibraryTokens($connections->store());

                foreach (array_keys((array) json_decode((string) File::get($tokens), true)) as $library) {
                    $set = $old->get((string) $library);

                    if ($set !== null && $store->get((string) $library) === null) {
                        $store->put((string) $library, $set);
                    }
                }

                File::delete($tokens);
            } catch (Throwable) {
                // Tried again next time.
            }
        }
    }
}
