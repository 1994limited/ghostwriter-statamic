# API keys

Ghostwriter writes with one AI provider, on your own account. It can also make images with a second provider, and search free photo libraries. Every key goes in your `.env` file, and Ghostwriter never stores any of them.

| Variable | Service | What for | Free? |
| --- | --- | --- | --- |
| `ANTHROPIC_API_KEY` | Anthropic (Claude) | Writing (the default) | No, pay as you go |
| `OPENAI_API_KEY` | OpenAI (ChatGPT) | Writing, or making images | No, pay as you go |
| `GEMINI_API_KEY` | Google (Gemini) | Writing, or making images | Free tier for writing with Flash models |
| `UNSPLASH_ACCESS_KEY` | Unsplash | Photo search | Yes |
| `PIXABAY_API_KEY` | Pixabay | Photo search | Yes |
| `PEXELS_API_KEY` | Pexels | Photo search | Yes |
| none | Openverse | Photo search (public domain and CC0 only) | Yes, no key needed |

You need **one** writing key. Everything else is optional.

After adding or changing a key, reload the Control Panel page. **Ghostwriter → Get started** shows which provider it is connected to.

> Keep keys out of version control. `.env` should already be in your `.gitignore`. Use a separate key for each site, so you can see what each one spends and revoke one without affecting the others.

## Choosing a writing provider

All three write well. Claude is the default and the one Ghostwriter's prompts were tuned on.

- **Claude (Anthropic):** the default. Strong at matching a voice and following the brief closely.
- **ChatGPT (OpenAI):** a good choice if you also want to make images with the same account.
- **Gemini (Google):** the only one with a free tier. On the free tier, set the model to a Flash model (see below).

Choose the provider under **Ghostwriter → Settings → Provider**, or with `provider` in [`config/ghostwriter.php`](configuration.md). Ghostwriter connects to all three itself; nothing else needs setting up.

Keys are read through `keys` in `config/ghostwriter.php`, which takes them from the variables above. A key still set in `config/ai.php`, from before 1.1, is used when the variable is empty.

## Anthropic (Claude)

1. Go to the [Claude Console](https://platform.claude.com) and create an account.
2. Open **Settings → Billing** and add credit. The API is pay as you go, with no free tier, and keys don't work until there is credit on the account.
3. Open **Settings → API keys** ([direct link](https://platform.claude.com/settings/keys)) and click **Create key**. Name it after the site.
4. Copy the key straight away; it is only shown once.
5. Add it to `.env`:

   ```dotenv
   ANTHROPIC_API_KEY=sk-ant-...
   ```

Keys made inside a workspace only work for that workspace's settings; a key that gives "not scoped to a workspace" errors needs making again from the default workspace. You can set a monthly spend limit on the Billing page.

## OpenAI (ChatGPT, and images)

1. Go to the [OpenAI platform](https://platform.openai.com) and create an account.
2. Under **Settings → Billing**, add a payment method or prepaid credit.
3. Open **API keys** ([direct link](https://platform.openai.com/api-keys)) and click **Create new secret key**. Copy it; it is only shown once.
4. Add it to `.env`:

   ```dotenv
   OPENAI_API_KEY=sk-...
   ```

To **write** with OpenAI, set **Provider** to OpenAI. To **make images** with it while writing with Claude, leave Provider as Anthropic. Ghostwriter uses any image provider that has a key, or the one chosen under **Image provider**.

OpenAI may ask you to verify your organisation before its image models can be used. If making an image fails with a message about verification, complete it under **Settings → Organization** in the OpenAI platform.

## Google (Gemini)

1. Go to [Google AI Studio](https://aistudio.google.com/apikey) and sign in with a Google account.
2. Click **Create API key**. If asked, choose or create a Google Cloud project for it.
3. Copy the key and add it to `.env`:

   ```dotenv
   GEMINI_API_KEY=...
   ```

**Using the free tier.** The free tier covers Gemini's Flash models, with daily limits. Ghostwriter's default Gemini model is a Flash model, so to write for free, set **Provider** to Gemini and leave **Model** blank. If you set a model, choose a 3.x Flash model: Google now limits the older 2.5 models to accounts that have used them before. Two things to know:

- **Google may use what you send to improve its products.** That includes excerpts of your entries and drafts. For client sites, or anything confidential, turn on billing for the project so the paid terms apply.
- **Making images is not on the free tier.** Gemini's image model needs billing turned on for the project.

Check [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing) for the current free models and limits.

## Free photo libraries

**Find a photo** searches every library that is switched on, and the model picks the best matches for your site. Each library you add gives it more to choose from.

### Openverse (no key)

On by default. Openverse is searched for **public-domain and CC0** work only, so nothing found there comes with conditions. Turn it off with `images.openverse` in [`config/ghostwriter.php`](configuration.md).

### Unsplash

1. Create an account at [unsplash.com](https://unsplash.com/join).
2. Go to [Your apps](https://unsplash.com/oauth/applications), click **New Application**, accept the API terms, and give it a name and description.
3. Copy the **Access Key** (not the Secret key) and add it to `.env`:

   ```dotenv
   UNSPLASH_ACCESS_KEY=...
   ```

New Unsplash apps start in **demo mode**, limited to 50 requests an hour. That is enough for one person choosing photos now and then: a search is one request and a photo picked is two. For more, apply for production access from your app's page.

Ghostwriter tells Unsplash each time a photo is used, as their guidelines ask, and saves the photographer, library and licence on the asset (see [Images](images.md#credits)). Read the [Unsplash API guidelines](https://unsplash.com/documentation) before using it on a production site.

### Pixabay

1. Create an account at [pixabay.com](https://pixabay.com/accounts/register/).
2. While logged in, open the [Pixabay API documentation](https://pixabay.com/api/docs/). Your key is shown in the **Parameters** section, next to `key`.
3. Add it to `.env`:

   ```dotenv
   PIXABAY_API_KEY=...
   ```

Free, with up to 100 requests a minute. Pixabay doesn't allow linking straight to its images, so Ghostwriter downloads the photo you choose into your asset container, which is what Pixabay asks for.

### Pexels

Pexels keys are free but Pexels sometimes pauses issuing new ones. If you have one:

```dotenv
PEXELS_API_KEY=...
```

## Checking it works

Open **Ghostwriter → Get started**. The first step, **Connect a model**, shows the provider it is connected to, or which key is missing, and says so if another provider's key is set instead. On the image button, only the libraries and options with a key are offered.

Next: [Get started](getting-started.md).
