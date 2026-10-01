# Troubleshooting

## "No API key is set for the … provider"

The chosen provider's key isn't in the environment. Add it to `.env` (see [API keys](api-keys.md)) and reload the page. On a server, check the variable is set where your host keeps environment variables, then redeploy. If the key is set but calls fail with "not scoped to a workspace", make the key again from the account's default workspace.

## Nothing happens after asking for something

Model calls run in the background.

- **Using a queue connection?** Make sure a worker is running (`php artisan queue:work`, Horizon, or your host's daemon).
- **On the `sync` driver?** The call runs after the response is sent, which needs PHP-FPM. `php artisan serve` blocks; use Herd, Valet or your server.
- **Look at the log.** `storage/logs/laravel.log` records what went wrong.

## A model call times out

Long drafts can take several minutes on the larger models. Raise `timeout` in [`config/ghostwriter.php`](configuration.md) (or `GHOSTWRITER_TIMEOUT`), for example to `600`. If you run a queue worker with its own time limit, raise that too.

## "The analysis came back in a form that could not be read. Try again."

When learning a kind, the model's answer couldn't be read, even after one automatic retry. Try again; it usually works the second time. If it keeps happening for one collection, teach the kind by hand and choose fewer, more typical example entries. The log holds the reply.

## "Ghostwriter did not come back with … it could read"

The reply couldn't be parsed. Ghostwriter repairs the usual problems (apostrophes, quotation marks, colons) itself, so this is rare; try again. The raw reply is in the log.

## The draft is put in, but something is missing

Read the notes above the form after **Use this draft**. They list:

- blocks the draft used that this blueprint doesn't have (left out)
- choices, related entries and links still for you to set
- links pointing to `https://example.com` for now
- image fields with a striped placeholder
- blocks whose content is the same on every entry, where the copy was used in place of what was drafted

## "The given data was invalid" while typing a reply

An older version let **⌘↵** in the reply box reach the entry form's save shortcut. Update the addon and republish its assets.

## No Ghostwriter button on an assets field

The button only appears:

- on entry create and edit screens in collections Ghostwriter writes for
- for people with the Ghostwriter permission
- on fields whose validation allows images (a `mimes:pdf` rule hides it)
- on fields with a container

## No "Make one" tab

Making images needs an OpenAI or Gemini key (Gemini needs billing turned on for image models). OpenAI may ask you to verify your organisation first.

## No "Logo card" tab

Logo cards need the Imagick PHP extension. Ask your host to turn it on, or check with `php -m | grep imagick`.

## Photo search finds little

- Add an Unsplash or Pixabay key: Openverse on its own has a smaller, more archival collection.
- Type simple, concrete searches: "stone wall garden", not "sustainable landscaping".
- Unsplash's demo mode allows 50 requests an hour.

## The Control Panel screens look broken after an update

Publish the assets again:

```bash
php artisan vendor:publish --tag=ghostwriter-statamic --force
```

## Gemini: "quota exceeded" or "model not found" on the free tier

The free tier covers Flash models only, with daily limits. Leave **Model** blank to use Ghostwriter's default Gemini model, which is a Flash model, or set a 3.x Flash model, or turn on billing. Google limits the older 2.5 models to accounts that have used them before. See [API keys](api-keys.md#google-gemini).

## Seeing what went wrong

Ghostwriter logs errors and unreadable model replies to Laravel's log, `storage/logs/laravel.log`, prefixed "Ghostwriter:".
