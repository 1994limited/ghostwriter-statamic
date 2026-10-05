<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Connections\EncryptedCredentialStore;
use NineteenNinetyFour\Ghostwriter\Core\Connections\CredentialStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\CredentialStoreContract;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's CredentialStoreContract against the encrypted file, which a site
 * without a database (or before migrating) keeps its connections in.
 */
class CredentialStoreTest extends TestCase
{
    use CredentialStoreContract;

    private ?EncryptedCredentialStore $store = null;

    protected function contractStore(): CredentialStore
    {
        return $this->store ??= new EncryptedCredentialStore;
    }

    protected function contractRaw(string $name): ?string
    {
        $path = EncryptedCredentialStore::path();
        $all = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        return is_string($all[$name] ?? null) ? (string) file_get_contents($path) : null;
    }

    public function test_the_file_is_never_committed_and_only_its_owner_reads_it(): void
    {
        $this->contractStore()->put('pexels', ['fields' => ['key' => 'pexels-0123456789']]);

        $this->assertSame('file', $this->contractStore()->where());
        $this->assertSame("*\n", file_get_contents(dirname(EncryptedCredentialStore::path()).'/.gitignore'));
        $this->assertSame('0600', substr(sprintf('%o', fileperms(EncryptedCredentialStore::path())), -4));
    }
}
