<?php

namespace NineteenNinetyFour\Ghostwriter\Connections;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Connections\CredentialStore;
use SensitiveParameter;
use Throwable;

/**
 * Where Settings → Connections keeps what was set up (core's
 * CredentialStore), every value encrypted with the app's key (Laravel's
 * Crypt, APP_KEY):
 *
 * - **in the database**, in `ghostwriter_credentials`, when the site has
 *   one and `php artisan migrate` has made the table;
 * - otherwise in **`storage/ghostwriter/credentials/credentials.json`**,
 *   beside Ghostwriter's other working files, with a `.gitignore` of its
 *   own so it is never committed, readable by the owner only.
 *
 * Never in content/ or the addon's settings YAML. Once the table exists,
 * anything in the file is moved into it and the file removed. A value that
 * can't be decrypted (the app key changed) reads as none.
 */
class EncryptedCredentialStore implements CredentialStore
{
    public const TABLE = 'ghostwriter_credentials';

    private ?bool $database = null;

    public function get(string $name): ?array
    {
        $sealed = $this->usesDatabase()
            ? DB::table(self::TABLE)->where('name', $name)->value('value')
            : ($this->file()[$name] ?? null);

        if (! is_string($sealed) || $sealed === '') {
            return null;
        }

        try {
            $value = json_decode(Crypt::decryptString($sealed), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    public function put(string $name, #[SensitiveParameter] array $value): void
    {
        $sealed = Crypt::encryptString((string) json_encode($value));

        if ($this->usesDatabase()) {
            $updated = DB::table(self::TABLE)->where('name', $name)->update(['value' => $sealed, 'updated_at' => now()]);

            if ($updated === 0 && ! DB::table(self::TABLE)->where('name', $name)->exists()) {
                DB::table(self::TABLE)->insert(['name' => $name, 'value' => $sealed, 'created_at' => now(), 'updated_at' => now()]);
            }

            return;
        }

        $all = $this->file();
        $all[$name] = $sealed;
        $this->write($all);
    }

    public function forget(string $name): void
    {
        if ($this->usesDatabase()) {
            DB::table(self::TABLE)->where('name', $name)->delete();

            return;
        }

        $all = $this->file();

        if (array_key_exists($name, $all)) {
            unset($all[$name]);
            $this->write($all);
        }
    }

    /** 'database' or 'file', for the docs and the Connections page. */
    public function where(): string
    {
        return $this->usesDatabase() ? 'database' : 'file';
    }

    /** What is kept for a name as it is at rest (still encrypted), for tests. */
    public function raw(string $name): ?string
    {
        $sealed = $this->usesDatabase() ? DB::table(self::TABLE)->where('name', $name)->value('value') : ($this->file()[$name] ?? null);

        return is_string($sealed) ? $sealed : null;
    }

    public static function path(): string
    {
        return dirname((string) config('ghostwriter.sessions_path')).'/credentials/credentials.json';
    }

    /**
     * The table, when the site has a database and the migration has run.
     * Asked once per request or job.
     */
    public function usesDatabase(): bool
    {
        if ($this->database !== null) {
            return $this->database;
        }

        try {
            $this->database = Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            $this->database = false;
        }

        if ($this->database && is_file(self::path())) {
            $this->moveFileIntoDatabase();
        }

        return $this->database;
    }

    private function moveFileIntoDatabase(): void
    {
        foreach ($this->file() as $name => $sealed) {
            if (is_string($sealed) && ! DB::table(self::TABLE)->where('name', $name)->exists()) {
                DB::table(self::TABLE)->insert(['name' => $name, 'value' => $sealed, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        File::delete(self::path());
    }

    /**
     * @return array<string, mixed>
     */
    private function file(): array
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
        $path = self::path();
        $dir = dirname($path);

        File::ensureDirectoryExists($dir, 0700);

        if (! is_file($dir.'/.gitignore')) {
            File::put($dir.'/.gitignore', "*\n");
        }

        File::put($path, (string) json_encode($all, JSON_PRETTY_PRINT));
        @chmod($path, 0600);
    }
}
