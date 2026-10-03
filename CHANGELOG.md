# Changelog

## 1.1.1 - 2026-10-03

### Fixed
- Finish this page: **Link to …** on a Link field (in URL mode, holding `#gw-link:`) set the form's value but the field kept showing the old address. It now switches the field to Entry with the entry showing, through the field's own meta, and the value is `entry::<id>`.
- **Choose an entry** on such a Link field only focused it. It now switches the field to Entry and opens its entry selector; cancelling puts the field back to URL and the link to choose. On a link inside Bard it opens Bard's own link editor on the link's words.
- A fix counts as done only when the field's value really changed, read back from the form; the guide no longer moves on otherwise.
- The guide and the flying mark step out of the way while a selector or dialog (entry picker, asset browser, License & replace) is open.
- **Remove it** leaves no stray space where the text was.

## 1.1.0 - 2026-10-03

Stock photos from paid libraries, licensed from your own account; **Finish this page**, which marks what only a person can finish and walks you through it; and drafts that start unpublished. Requires `1994/ghostwriter-core` 1.4. Statamic 6.30 or later and PHP 8.3 or later, as before.

### Added
- **Finish this page.** Ghostwriter never makes up a fact: where a draft needs a price, a date, a name or a quote it wasn't given, it writes `[[ask: adult ticket price]]`, and a link it can't place points at `#gw-link:contact-page`. On any entry in a collection Ghostwriter writes for, whoever wrote it, the form then shows:
  - a count beside **Save** ("5 things to finish", then "Ready to publish");
  - highlighted fields (amber to do, indigo current, green fixed) with a numbered tag beside each field's name, and the markers underlined inside Bard;
  - a guide that steps through each gap: an answer box for a fact, **Link to …** (an entry whose title or slug matches), **Find a photo**, **Choose from Assets**, **Leave it empty**, **License** (the stock photos License & replace), and, each one small model call, **Write around it** and **Write it for me**. Ghostwriter never fills in a fact. Fixes go into the form; nothing is saved until you save;
  - the Ghostwriter mark flying to the field, and the guide tucking into a corner button with a count, all still under reduced motion;
  - Alt+Shift+N / P / G, a live region and labelled tags for keyboard and screen-reader users; dark mode; a bottom sheet on phones.
  It's checked as you type, for nothing (no model, no save), remembered per person (it starts tucked away) and opens after **Use this draft** (`finish.open_after_draft`). See [Finish this page](docs/finish-this-page.md).
- **Stock photos** from paid libraries, licensed from your own account with your own keys. See [Stock photos](docs/stock-photos.md).
  - **Shutterstock (API plan required).** Set `SHUTTERSTOCK_API_KEY` and `SHUTTERSTOCK_API_SECRET`, then **Connect account** on the settings screen. Its own watermarked previews are shown; nothing is stored. On a local site its sandbox is used. Getty Images and iStock are coming: their keys can be set now.
  - **Demo stock (no charge)** to try the whole flow on a local site, never in production.
  - **Search in** (free libraries, a paid library or everything), cost and **Editorial** chips, and **Include editorial images** with a short explanation, offered only for libraries that have editorial images.
  - **Insert preview**: a labelled stand-in in the field, the watermarked comp kept privately for signed-in editors (and shown to them in Live Preview), and a **Preview · not licensed** badge.
  - **License & replace**: the options, what it uses from your account and the credit line, then the licensed original swapped in byte for byte, keeping alt text, title and focal point. Never bought twice.
  - A **stock image ledger** (one YAML file per photo in `content/ghostwriter/stock/`, made when first needed), a **Stock images** screen with a CSV export, a **Stock photo** panel in the asset editor, and `ghostwriter:stock-cleanup` hourly on the scheduler.
  - A **License stock images** permission, given to nobody by default (super users have it), as licensing spends from the site's account.
- A "Finish this page" section on the settings screen: **When a page with something unfinished is published**, Block (default) or Warn.
- Continuous integration: PHPUnit and Pint on PHP 8.3 and 8.4 against the lowest and newest dependencies, and the guide's logic on Node.

### Changed
- **One publish guard.** A page can't be published, scheduled or have its working copy published while it holds a fact to add, a link to choose or to a deleted entry, an image placeholder, template text left in, or a stock preview not licensed. One message per field ("Add adult ticket price before publishing."); saving unpublished always works. `publish.on_unfinished` (`GHOSTWRITER_ON_UNFINISHED_PUBLISH`) or the settings screen can make it a warning; `stock.on_publish` is still read when it isn't set.
- **Drafts start unpublished.** **Use this draft** on a new or unpublished entry switches the form's Published toggle off (`drafts_unpublished`, default `true`). A published entry is left as it is.
- After **Use this draft**, image placeholders and links still to choose are Finish this page's steps, not lines in the notes notice.
- Images from a paid library, and any file named or credited as Getty Images or iStock, are never sent to a model.
- Requires `1994/ghostwriter-core` 1.4 (was 1.0).

### Fixed
- A link a new page usually has, but whose target the examples didn't agree on, pointed at `https://example.com`, a real address if published. It now points at `#gw-link:<hint>`, a place on the same page, and publishing waits until it's chosen.

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
