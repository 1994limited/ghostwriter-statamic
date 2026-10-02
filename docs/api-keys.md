# API keys

Ghostwriter writes with one provider, on your own account. It can also make images with a second provider, and search free photo libraries. Every key goes in your `.env` file. Ghostwriter reads it there each time it needs it, and never stores or shows it.

| Variable | Service | What for | Free? |
| --- | --- | --- | --- |
| `ANTHROPIC_API_KEY` | Anthropic (Claude) | Writing (the default) | No, pay as you go |
| `OPENAI_API_KEY` | OpenAI (ChatGPT) | Writing, or making images | No, pay as you go |
| `GEMINI_API_KEY` | Google (Gemini) | Writing, or making images | Free tier for writing with Flash models |
| `UNSPLASH_ACCESS_KEY` | Unsplash | Photo search | Yes |
| `PIXABAY_API_KEY` | Pixabay | Photo search | Yes |
| `PEXELS_API_KEY` | Pexels | Photo search | Yes |
| none | Openverse | Photo search (public domain and CC0 only) | Yes, no key needed |

You need **one** writing key. Everything else is optional. After adding or changing a key, reload the Control Panel page (and run `php artisan config:clear` if your config is cached).

> Keep keys out of version control. `.env` should already be in your `.gitignore`. Use a separate key for each site, so you can see what each one spends and revoke one without affecting the others.

## Choosing a writing provider

Ghostwriter works with three providers, through its own connection to each: Anthropic, OpenAI and Google. It doesn't use the Laravel AI SDK, and no other provider is supported.

- **Claude (Anthropic):** the default, and the one the prompts were tuned on. Strong at matching a voice and following the brief closely.
- **ChatGPT (OpenAI):** a good choice if you also want to make images with the same account.
- **Gemini (Google):** the only one with a free tier. Leave **Model** blank to use the default Flash model.

Choose under **Ghostwriter → Settings → Provider**, or with `provider` in [`config/ghostwriter.php`](configuration.md). Leave **Model** blank for the provider's default. These were checked on 2026-10-01 and come from Ghostwriter Core:

| Provider | Writing | Images |
| --- | --- | --- |
| Anthropic | `claude-opus-5-5` | (doesn't make images) |
| OpenAI | `gpt-6.1-sol` | `gpt-image-2.5-sunburst` |
| Google | `gemini-3.8-flash` | `gemini-3.1-flash-image` |

With no **Image provider** chosen, Ghostwriter makes images with OpenAI if it has a key, otherwise Gemini.

## Anthropic (Claude)

1. Go to the [Claude Console](https://platform.claude.com) and create an account.
2. Open **Settings → Billing** and add credit. The API is pay as you go, with no free tier, and keys don't work until there is credit on the account.
3. Open **Settings → API keys** ([direct link](https://platform.claude.com/settings/keys)) and click **Create key**. Name it after the site.
4. Copy the key straight away; it is only shown once.
5. Add it to `.env`:

   ```dotenv
   ANTHROPIC_API_KEY=sk-ant-...
   ```

A key made inside a workspace only works for that workspace; one that gives "not scoped to a workspace" errors needs making again from the default workspace. You can set a monthly spend limit on the Billing page.

## OpenAI (ChatGPT, and images)

1. Go to the [OpenAI platform](https://platform.openai.com) and create an account.
2. Under **Settings → Billing**, add a payment method or prepaid credit.
3. Open **API keys** ([direct link](https://platform.openai.com/api-keys)) and click **Create new secret key**. Copy it; it is only shown once.
4. Add it to `.env`:

   ```dotenv
   OPENAI_API_KEY=sk-...
   ```

To **write** with OpenAI, set **Provider** to ChatGPT (OpenAI). To **make images** with it while writing with Claude, leave **Provider** as Claude.

OpenAI may ask you to verify your organisation before its image models can be used. If making an image fails with a message about verification, complete it under **Settings → Organization** on the OpenAI platform.

## Google (Gemini, and images)

1. Go to [Google AI Studio](https://aistudio.google.com/apikey) and sign in with a Google account.
2. Click **Create API key**. If asked, choose or create a Google Cloud project for it.
3. Copy the key and add it to `.env`:

   ```dotenv
   GEMINI_API_KEY=...
   ```

**The free tier** covers Gemini's Flash models, with daily limits. To write for free, set **Provider** to Gemini (Google) and leave **Model** blank. If you set a model, choose a 3.x Flash model: Google limits the older 2.5 models to accounts that have used them before. Two things to know:

- **Google may use what you send to improve its products** on the free tier. That includes excerpts of your entries and drafts. For client sites, or anything confidential, turn on billing for the project so the paid terms apply.
- **Making images isn't on the free tier.** Gemini's image model needs billing turned on.

Check [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing) for the current free models and limits.

## Gateways and proxies

To send a provider's requests through a gateway that speaks the same API, set its address:

```dotenv
GHOSTWRITER_ANTHROPIC_BASE_URL=https://gateway.example.com/anthropic
GHOSTWRITER_OPENAI_BASE_URL=https://gateway.example.com/v1
GHOSTWRITER_GEMINI_BASE_URL=https://gateway.example.com/gemini
```

These are `base_urls.anthropic`, `base_urls.openai` and `base_urls.gemini` in `config/ghostwriter.php`. The address must be `https://`, except on `localhost`, `127.0.0.1` or `[::1]`, and can't hold a query string or credentials. Leave it unset to use the provider's own address.

## Free photo libraries

**Find a photo** searches every library that is switched on, and the model picks the best matches. Each library you add gives it more to choose from.

### Openverse (no key)

On by default. Openverse is searched for **public-domain and CC0** work only, so nothing found there comes with conditions. Turn it off with **Search Openverse** in the settings, or `images.openverse` (`GHOSTWRITER_OPENVERSE`) in [`config/ghostwriter.php`](configuration.md), which wins over the screen.

### Unsplash

1. Create an account at [unsplash.com](https://unsplash.com/join).
2. Go to [Your apps](https://unsplash.com/oauth/applications), click **New Application**, accept the API terms, and give it a name and description.
3. Copy the **Access Key** (not the Secret key) and add it to `.env`:

   ```dotenv
   UNSPLASH_ACCESS_KEY=...
   ```

New Unsplash apps start in **demo mode**, limited to 50 requests an hour. That is enough for one person choosing photos now and then: a search is one request and a photo picked is two. For more, apply for production access from your app's page.

Ghostwriter tells Unsplash each time a photo is used, as their guidelines ask, and saves the photographer, library and licence on the asset (see [Images](images.md#names-alt-text-and-credits)). Read the [Unsplash API guidelines](https://unsplash.com/documentation) before using it on a production site.

### Pexels

Pexels keys are free, though Pexels sometimes pauses issuing new ones. If you have one:

```dotenv
PEXELS_API_KEY=...
```

### Pixabay

1. Create an account at [pixabay.com](https://pixabay.com/accounts/register/).
2. While logged in, open the [Pixabay API documentation](https://pixabay.com/api/docs/). Your key is shown in the **Parameters** section, next to `key`.
3. Add it to `.env`:

   ```dotenv
   PIXABAY_API_KEY=...
   ```

Free, with up to 100 requests a minute. Pixabay doesn't allow linking straight to its images, so Ghostwriter downloads the photo you choose into your asset container, which is what Pixabay asks for.

## Busy providers and retries

A provider that is busy, limiting requests or briefly down is tried again by itself. The statuses retried are 408, 409, 429, 500, 502, 503, 504 and 529, and dropped connections, up to three attempts in all. Ghostwriter waits as long as the provider asks (`retry-after`), up to 30 seconds, or backs off for a moment if it doesn't say. A call that times out waiting for an answer isn't retried, as the model may still be working on it.

This isn't configurable. It is why each queued job is allowed `timeout × 3 + 60` seconds (see [The queue](installation.md#the-queue)).

On Claude models that support it, a request Claude declines is passed to the model Anthropic recommends instead. This is on by default; turn it off with `GHOSTWRITER_ANTHROPIC_FALLBACKS=false`.

## Checking it works

- **Get started**, step 1 (**Connect a model**), names the provider Ghostwriter is connected to, or the key that is missing.
- **Ghostwriter → Settings** lists each key under **API keys** as **Set** or **Not set**. The keys themselves are never shown.
- On the image button, only the options with a key are offered.

Next: [Permissions](permissions.md).
