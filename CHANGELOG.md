# Changelog

## Unreleased

### Added
- **Stock image ledger.** Every photo Ghostwriter puts into the site from a photo library is recorded: the library and photo, the asset, its licence and credit, who added it and when, and where it is used. One YAML file per record in `content/ghostwriter/stock/` (`ghostwriter.stock_path`), so it is committed with the site. Records are never deleted.
- Where each ledger image is used is kept up to date as entries are saved (inside Replicator and Bard sets too), as assets are moved or renamed, and when an asset is deleted. `php please ghostwriter:stock-usages` rescans every entry.

### Changed
- Requires `1994/ghostwriter-core` 1.1.
- Images from a paid library (and any file named or credited as Getty Images or iStock) are never sent to a model: not as references when finding or making a picture, nor as samples for the image style guide.

## 1.0.1 - 2026-10-02

### Changed
- The image button on assets fields is easier to spot: it now reads **Find a photo** beside the ghost, at the size of the field's own controls, instead of a 10px icon.
- Requires Statamic 6.30 or later (was `^6.0`). The settings screen relies on an API added in 6.30, so earlier 6.x releases failed when the addon booted. Tested on 6.30 with Laravel 12.40, and on 6.35 with Laravel 13.
- Requires `guzzlehttp/psr7` 2.6 or later, which the connection to the providers already needed; an older lock could have resolved 1.x.

### Fixed
- On a phone, the writing panel no longer runs off the side of the screen: the conversation and the draft stack and fit the width, the draft's buttons wrap, and image choices under a draft stack above their controls.
- On a phone, **Write with Ghostwriter** / **Edit with Ghostwriter** shows as the ghost alone, so **Save & Publish** keeps its shape beside it.

## 1.0.0 - 2026-10-02

First release. Ghostwriter learns how your site writes and what its pictures look like, then drafts new entries and edits existing ones in that voice, in a panel beside the entry form. Get started walks through setup, the Overview shows what is in progress, and a widget sits on the Control Panel's dashboard.

### Writing
- **Write with Ghostwriter** on a collection's Create Entry screen, its entry list, the Overview, the widget and the content plan.
- Choose a kind you taught it, something like what is already here, or a general brief that works on every collection with no setup.
- The brief: answer its questions, or give a working title and notes and have it fill them in. **Model it on** up to six entries.
- A follow-up conversation: Ghostwriter asks at most three questions when it needs facts only you know, then takes changes in plain words. It never invents figures, quotes or client names.
- The draft in **Blocks** and **Text** views. Click any writing (or Tab to it) to change it in place; **Edit YAML** for the whole draft.
- **Use this draft** fills the form, to check and save. Nothing is published for you.
- **Edit with Ghostwriter** on an existing entry changes its writing in conversation, keeping images, links and settings.
- Text, Bard, Markdown, Replicator, Grid and Group fields, with sets written to the collection's usual shape.

### Learning the site
- **Voice guide**, written from your published entries, edited by hand or by asking for a change.
- **Image style guide**, written from the images your entries use, a section per collection.
- **Kinds of content**: suggested per collection with examples and why each is worth teaching, learned on request, or taught by hand with their own questions, guidance, checklist and examples.
- Guides, kinds and the plan are files in your project, to commit with it.

### House style
- New entries copy what your entries agree on: block settings, links, nested items and how rich text is dressed.

### Images
- A Ghostwriter button on every assets field: **Find a photo** on Openverse, Unsplash, Pexels and Pixabay, ranked by the model against the page's words and the images already in that place, with a second round of searches when nothing fits.
- Found photos are named, titled and given alt text from the library's description, with the credit and licence saved on the asset.
- **Make the picture** with OpenAI or Gemini, in the style of the images already there, optionally with your own image in it.
- Photos offered for every image field under a draft, and striped placeholders where a new entry is still missing an image (**Mark images still to choose**).

### Content plan
- Ideas for entries each collection is missing, reviewed before they join the plan, optionally steered. A new batch joins any still waiting. **Draft this** opens a new entry with the brief filled in.
- A piece started from the plan shows its stage there; **Back to ideas** returns one not yet saved to the plan, and **Put back** returns a dismissed idea.

### Teams
- Conversations are shared with everyone who may use Ghostwriter, showing who sent each message and who started and last changed each piece. Turn this off with `shared_conversations`.
- One permission, **Write content and edit the voice guide with Ghostwriter**. Putting a draft or image into an entry needs Statamic's own permission to do so by hand. Super users and those who may edit the addon's settings manage Ghostwriter.

### Providers
- Writing with Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini); images with OpenAI or Gemini. Defaults: `claude-opus-5-5`, `gpt-6.1-sol` and `gemini-3.8-flash` for writing; `gpt-image-2.5-sunburst` and `gemini-3.1-flash-image` for images.
- Keys are read from `.env` (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`) and never stored or shown.
- `base_urls` for a gateway or proxy that speaks a provider's own API.
- A busy or rate-limited provider is tried again, up to three attempts in all, following its `retry-after`.
- A reply cut off at its length limit is asked for once more with twice the room; a draft or the voice guide is never saved half-written.
- Each call is logged with the provider, model, tokens and time, never the words.

### Requirements
- PHP 8.3 or later, with GD; Statamic 6.
- An API key for Anthropic, OpenAI or Gemini.
- A queue worker (`--timeout=960`), or PHP-FPM on the `sync` queue.
- Work whose worker was stopped before it finished (a time limit, a restart) shows as failed once its job can no longer be running, ready to try again. This holds for conversations, the plan, the guides, kinds and images.
