# Connections

**Tools → Ghostwriter → Connections** is where a site sets up every outside service Ghostwriter uses. Only people who may change Ghostwriter's settings (super users, or the "Edit Ghostwriter settings" permission) see it.

The cards come in three groups:

- **Writing:** Anthropic, OpenAI, Gemini and OpenRouter. OpenAI, Gemini and OpenRouter also make images; there are no separate image cards.
- **Images:** Unsplash, Pexels and Pixabay (free photo libraries), and Openverse, which needs no key.
- **Stock photos:** Shutterstock (key and secret, then **Connect account** to license).

## Setting one up

1. Click **Set up** on the card.
2. **Open {Service}** opens the page where its key is made, in a new tab. Follow the two or three steps on the card.
3. Paste the key (and the secret, for Shutterstock) and click **Check & save**. Ghostwriter makes one small call to the service with it (a list of one model, or a search for one photo). If the service refuses it, the card says why, plainly, and nothing is kept.

The card then says **Connected · key ending ••a1b2**. Ghostwriter never shows more of a key than its last four characters. **Replace key** swaps it; **Disconnect** (after a confirm) forgets it. The key still works at the service until you delete it there.

OpenRouter can also **Connect with OpenRouter** from its card: sign in, and OpenRouter makes the key for you.

If a service starts refusing a key that was working (it was deleted, or the account ran out), the card says **Key stopped working** the next time Ghostwriter uses it. Replace the key, and it clears.

## .env always wins

Every key can still be set in `.env` (or `config/ghostwriter.php`): `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`, `OPENROUTER_API_KEY`, `UNSPLASH_ACCESS_KEY`, `PEXELS_API_KEY`, `PIXABAY_API_KEY`, `SHUTTERSTOCK_API_KEY` and `SHUTTERSTOCK_API_SECRET`. When one is set, it is used, its card says **Set in .env** (or **Set in config**), and **Set up** and **Replace key** aren't offered. Take it out of `.env` to manage it on the page instead.

The page says which environment you're on ("You're on local."). What is set up in Connections belongs to that site alone: your local, staging and live sites each keep their own keys, so what's connected can differ between them. Use `.env` (or your host's environment settings) to give every environment the same keys.

## Where keys are kept

Encrypted with your `APP_KEY` (Laravel's `Crypt`), and never in `content/`, the addon's settings YAML or anything else you commit:

- **In your database**, in the `ghostwriter_credentials` table, when the site has a database. Run `php artisan migrate` once to make it.
- **Otherwise in `storage/ghostwriter/credentials/credentials.json`**, readable only by its owner, with a `.gitignore` of its own so it is never committed. Once the table exists, Ghostwriter moves anything in the file into it and removes the file.

Changing `APP_KEY` makes kept keys unreadable: their cards go back to **Not set up**; set them up again.

Keys from before Connections (Connect with OpenRouter's key in `storage/ghostwriter/provider-keys.json`, Shutterstock's account in `library-tokens.json`) are moved in the first time Ghostwriter runs, and the old files removed.

## Keys never reach us

A key goes only to the service it belongs to: in the check, and in every call afterwards. Ghostwriter has no servers of its own that a key could be sent to.
