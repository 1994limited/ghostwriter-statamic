# Installation

This page covers what Ghostwriter needs, installing it, the queue it runs on, and updating or removing it.

## Requirements

- PHP 8.3 or later, with the GD extension (it draws the striped image placeholders).
- Statamic 6.
- An API key for one writing provider: Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini). See [API keys](api-keys.md).
- A queue worker, or PHP-FPM if your site uses the `sync` queue. See [The queue](#the-queue).

Optional:

- An OpenAI or Gemini key, to make images. Claude doesn't make images.
- The Imagick extension. Ghostwriter sends small copies of images to the model, made with Imagick when it is loaded and with GD otherwise.

## Install the addon

From your project's root:

```bash
composer require 1994/ghostwriter-statamic
```

This also installs `1994/ghostwriter-core` (`^1.0`) from Packagist: the part Ghostwriter shares with its Filament and Craft versions. No extra repository is needed.

Ghostwriter doesn't use the Laravel AI SDK (`laravel/ai`). It has its own connection to Anthropic, OpenAI and Gemini, and doesn't read `config/ai.php`, except that a key still set there is used when the matching `.env` variable is empty. If `config/ai.php` was only there for Ghostwriter, you can delete it.

Then publish the Control Panel assets:

```bash
php artisan vendor:publish --tag=ghostwriter-statamic --force
```

Add that line to your deploy script after `composer install`, so the assets reach the server too. `public/vendor/ghostwriter-statamic` can be left out of version control.

## Add your API key

Add the key for your provider to `.env`, for example `ANTHROPIC_API_KEY=sk-ant-...`. See [API keys](api-keys.md) for every key Ghostwriter can use.

## Who can use it

Ghostwriter adds one permission, **Write content and edit the voice guide with Ghostwriter**. See [Permissions](permissions.md).

## The queue

Writing a draft or a guide can take a minute or more, longer than a web request should be held open.

- **With a queue connection** (database, Redis and so on), each model call runs as a queued job. Keep a worker running, or nothing happens:

  ```bash
  php artisan queue:work --timeout=960
  ```

  If nothing picks the work up for a while, Ghostwriter says so: "Still waiting for a queue worker to pick this up. Is “php artisan queue:work” running?" It names the queue when it isn't `default`.
- **On the `sync` driver**, the default for a flat-file site, the call runs after the response has been sent, in the same PHP process, while the screen checks back for the result. This needs PHP-FPM, which Herd, Forge and most hosts use. `php artisan serve` handles one request at a time, so it blocks until the call is done.

**Why 960 seconds.** Each model call may take up to `timeout` seconds (300 by default). A provider that is busy or limiting requests is tried again, up to three attempts in all (see [Busy providers and retries](api-keys.md#busy-providers-and-retries)), so each job is allowed `timeout × 3 + 60` seconds: 960 with the default. Set your worker's own limit (`--timeout`, or Horizon's `timeout`) at least that high. On the database queue, also set `DB_QUEUE_RETRY_AFTER` above it, for example `1020`, so a long job isn't handed out twice. If you raise `GHOSTWRITER_TIMEOUT`, raise these too.

## Updating

```bash
composer update 1994/ghostwriter-statamic
php artisan vendor:publish --tag=ghostwriter-statamic --force
php artisan queue:restart
```

A running worker keeps the old code until it restarts, hence the last line. See the [changelog](../CHANGELOG.md) for what changed.

## Uninstalling

```bash
composer remove 1994/ghostwriter-statamic
```

Ghostwriter adds no database tables. Its guides, kinds and plan stay in `resources/ghostwriter/`, its settings in `resources/addons/ghostwriter-statamic.yaml`, and its working files in `storage/ghostwriter/`, until you delete them. Assets it saved stay in your containers: photos, made images and the striped placeholder, and any logo cards made before version 1.1.0.

Next: [Get started](getting-started.md).
