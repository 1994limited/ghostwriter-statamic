# Installation

## Requirements

- Statamic 6
- PHP 8.3 or later
- An API key for one AI provider: Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini). See [API keys](api-keys.md).

Optional:

- An OpenAI or Gemini key, to make images. Claude does not make images.
- The **Imagick** PHP extension, for logo cards (and SVG logos) and for sending smaller copies of images to the model. Without it, logo cards are not offered and images are sent at their original size up to 1 MB.
- The **GD** extension, which Statamic already needs, draws the striped image placeholders.

## Install the addon

From your project's root:

```bash
composer require 1994/ghostwriter-statamic
```

Statamic discovers the addon on install. Its Control Panel assets are published automatically; after an update, or if the screens look broken, publish them again:

```bash
php artisan vendor:publish --tag=ghostwriter-statamic --force
```

Add that line to your deploy script after `composer install`, so the assets land on the server too. `public/vendor/ghostwriter-statamic` can be left out of version control.

## Add your API key

Add the key for your chosen provider to your project's `.env` file:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
```

Ghostwriter reads keys from the environment each time it needs one. It never stores them, and they never appear in a settings file. See [API keys](api-keys.md) for every key it can use.

On a server, add the same variable wherever your host keeps environment variables (Laravel Forge, Ploi and Laravel Cloud all have a screen for this).

## Permissions

Ghostwriter adds one permission, under **Permissions** in a role: **Write content and edit the voice guide with Ghostwriter**.

- People with it see Ghostwriter in the navigation, the **Write with Ghostwriter** and **Edit with Ghostwriter** buttons, the image button on assets fields, and the dashboard widget.
- Putting a draft into an entry also needs Statamic's own permission to create entries in that collection, and editing an entry through Ghostwriter needs the permission to edit that entry. Ghostwriter never lets anyone change an entry they could not change by hand.
- Saving an image into an assets field needs the permission to upload to that field's container.
- A conversation belongs to whoever started it. Nobody else sees it on the dashboard or the widget, or can open it, super users aside.
- Ghostwriter's settings screen is seen by whoever may edit the addon's settings (the **Edit Ghostwriter settings** permission, or a super user).

## The queue

Writing a draft or a guide can take a minute or more, which is longer than a web request should be held open.

- **With a queue connection** (Redis, database, and so on) each model call runs as a queued job. Keep a worker running (`php artisan queue:work`, Horizon, or your host's daemon), or nothing will happen.
- **On the `sync` driver**, the default for a flat-file site, the call runs after the HTTP response has been sent, in the same PHP process, while the screen polls for the result. This needs PHP-FPM, which Herd, Forge and most hosts use. The single-threaded `php artisan serve` blocks until the call is done.

Each call is allowed the configured timeout (180 seconds by default). A call that finds the provider busy or rate-limited is tried again, up to three times in all, so each job is allowed three times the timeout plus 60 seconds (10 minutes by default). If your worker has its own time limit (`--timeout`, or Horizon's `timeout`), set it at least that high.

## Updating

```bash
composer update 1994/ghostwriter-statamic
php artisan vendor:publish --tag=ghostwriter-statamic --force
```

## Uninstalling

```bash
composer remove 1994/ghostwriter-statamic
```

Ghostwriter adds no database tables. Its guides, kinds and plan stay in `resources/ghostwriter/`, its settings in `resources/addons/ghostwriter-statamic.yaml`, and its working files in `storage/ghostwriter/`, until you delete them. Assets it saved (photos, made images, logo cards, the striped placeholder) stay in your containers.

Next: [API keys](api-keys.md).
