<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use Illuminate\Support\Facades\DB;
use NineteenNinetyFour\Ghostwriter\Connections\EncryptedCredentialStore;
use NineteenNinetyFour\Ghostwriter\Core\Connections\CredentialStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\CredentialStoreContract;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's CredentialStoreContract against the database table, once
 * `php artisan migrate` has made it.
 */
class DatabaseCredentialStoreTest extends TestCase
{
    use CredentialStoreContract;

    private ?EncryptedCredentialStore $store = null;

    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__.'/../../database/migrations/2026_10_07_000000_create_ghostwriter_credentials_table.php')->up();
    }

    protected function contractStore(): CredentialStore
    {
        return $this->store ??= new EncryptedCredentialStore;
    }

    protected function contractRaw(string $name): ?string
    {
        $value = DB::table(EncryptedCredentialStore::TABLE)->where('name', $name)->value('value');

        return is_string($value) ? $value : null;
    }

    public function test_it_uses_the_table_and_moves_the_file_into_it(): void
    {
        // Kept in the file before the table was there.
        $fileStore = new EncryptedCredentialStore;
        (function () {
            $this->database = false;
        })->call($fileStore);
        $fileStore->put('unsplash', ['fields' => ['key' => 'unsplash-0123456789']]);
        $this->assertFileExists(EncryptedCredentialStore::path());

        $store = new EncryptedCredentialStore;

        $this->assertSame('database', $store->where());
        $this->assertSame(['fields' => ['key' => 'unsplash-0123456789']], $store->get('unsplash'));
        $this->assertFileDoesNotExist(EncryptedCredentialStore::path(), 'The file is gone once moved.');
    }
}
