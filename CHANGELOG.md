# Changelog

## Unreleased

### Added
- **Finish this page, server side** (core 1.3). Ghostwriter finds what only a person can finish in an entry, whoever wrote it: a fact to add (`[[ask: adult ticket price]]`), a link to choose (`#gw-link:contact-page`, inline or in a `link` field), a link to an entry that's gone, the striped image placeholder, a stock preview not licensed, template text left in, and required or usually-filled fields left empty. Found for nothing: no model call, no save.
  - `POST cp/ghostwriter/finish/check` checks the publish form's values as they stand, for the count by Save and the guide; each gap comes with its message, speech label and fixes in the editor's words (`__()`), and the tab its field is on. "Link to …" is suggested from entries whose title or slug match the link's hint.
  - Using a draft returns its gaps too, and the conversation keeps what the draft left for a person, so the guide can say why ("I didn't want to guess").
  - `POST cp/ghostwriter/finish/fill`: **Write around it** (the sentence without the missing fact) and **Write it for me** (a summary from the page's own words), one small model call per click. Ghostwriter never fills in a fact.
  - `POST cp/ghostwriter/finish/guide` remembers, per person, whether the guide was left open or minimised (it starts minimised).
  - Core's ports, with its contract tests: `StatamicPlaceholderAssets`, `StatamicAssetRefs` (assets fields, Bard images and Bard sets) and `StatamicLinkTargets`; and `MarkerRoundTripContract` and `PublishGuardContract` through the addon's real apply path and saves.

- **Finish this page, on the entry's form.** A count by Save ("5 things to finish", then "Ready to publish"); amber, purple and green highlights with numbered tags on each field; Ghostwriter's markers underlined inside Bard (ProseMirror decorations, so they survive every edit, and fixes go through the editor so undo works); and a floating guide that steps through each gap with its fixes, Back, Skip for now and Next. It opens a field's tab and expands collapsed sets with Statamic's own reveal. Fixes write into the form, never a save: the answer box for a fact, Link to …, Find a photo, Choose from Assets, Leave it empty, License (the stock feature's own License & replace), Write around it and Write it for me.
  - The flying Ghostwriter mark follows the current field as the page scrolls; the guide tucks into a corner button with a count badge (sucked in, pop, wisps, a wave, a peek every few seconds, a leap and unfurl to restore, a pulse when the count changes), all still under reduced motion.
  - Keyboard: Alt+Shift+N / P / G, Esc in the guide; a polite live region; tags are buttons; fields get a hidden note. Dark mode, RTL, Windows high contrast, and a bottom sheet on phones.
  - Remembered per person (starts tucked away), and opens by itself after a draft is used (`finish.open_after_draft`). On a new, empty entry it waits for something that blocks publishing.
  - The guide shell (`resources/js/finish/shell.js`) is framework-free, with a five-part adapter for Statamic, so Craft and Filament can share it.

### Changed
- **One publish guard** for Finish this page and stock photos (core's `PublishReadiness`): a page can't be published, scheduled or have its working copy published while it holds a fact to add, a link to choose or to a deleted page, an image placeholder, template text, or an unlicensed stock preview. One message per field, by the form's path ("Add adult ticket price before publishing."); saving unpublished always works. `publish.on_unfinished` (`GHOSTWRITER_ON_UNFINISHED_PUBLISH`, or **When a page with something unfinished is published** on the settings screen) can make it a warning instead; it replaces the stock photos setting, and `stock.on_publish` is still read when it isn't set.
- A link a new page usually has but the draft can't settle now points at `#gw-link:<hint>` (a link to choose), not `https://example.com`.

### Added (stock photos)
- **Stock image ledger.** Every photo Ghostwriter puts into the site from a photo library is recorded: the library and photo, the asset, its licence and credit, who added it and when, and where it is used. One YAML file per record in `content/ghostwriter/stock/` (`ghostwriter.stock_path`), so it is committed with the site. Records are never deleted.
- **Stock photos settings.** A Stock photos section on the settings screen: a row for each paid library with whether its keys are set in `.env` (never the keys), **Check connection** (the account and what it can still buy), a switch for each library set up, the default source for "Search in", "Include editorial images by default", and what happens when a page with an unlicensed preview is published (Block or Warn; `stock.on_publish` in config wins). Getty Images and iStock are listed as coming: their keys can be set now.
- **Demo stock (no charge)**: a pretend paid library for trying the whole preview and licence flow. Only on a local site (or where `stock.demo` turns it on), never in production.
- **Search in** in the image dialog's Find a photo tab: the free libraries, a paid library, or everything (each library's best taking turns). Each person's last choice is remembered. Result cards show the library and the cost ("Free", "1 download", "3 credits", or "Paid"), and an **Editorial** chip with its restrictions; editorial images are left out unless "Include editorial images" is ticked. Paid results are never judged by a model and come in the library's own order, which the dialog says.
- **Insert preview** for a paid photo: the field gets a labelled stand-in image (with the photo's title, alt text and aspect ratio), the library's watermarked comp is kept privately for signed-in editors, and the ledger records it as a preview. "Preview added. Only signed-in editors see the photo; license it before publishing."
- The comp is served only in the Control Panel, from `cp/ghostwriter/stock/{id}/comp`, never cached (`Cache-Control: private, no-store`) or indexed (`X-Robots-Tag: noindex`).
- **"Preview · not licensed"** badge beside the image button on an assets field that holds a stock preview, with the comp's thumbnail. It opens the preview with **License**, or "Ask a manager to license" and **Request licence** for people without the permission. **Refresh preview** once the comp's period is over (once per preview); **Download again and replace** if a licensed file didn't go in.
- A **Stock photo** panel in the asset editor of any asset in the ledger: its state, library, ID, order and credit, with the same actions.
- **License & replace**: the confirm step shows the licence options, what it uses from your account ("Uses 1 of your 742 remaining downloads (…)"), any editorial restrictions and seat notices, and the credit line to show for editorial use. The licence is bought once (a second press is refused), then the stand-in's file is swapped for the licensed original with `Asset::reupload()`: same asset and path, so every reference stays, and its alt text, title and focal point are kept. The original is never re-encoded. Failures say what happened and never buy twice; an unknown outcome says "Ghostwriter will check with the library in a few minutes; don't buy it again."
- In **Live Preview**, signed-in editors see the comp in place of the stand-in (any `src` or `srcset` ending in the stand-in's file name, Glide addresses included). Shared preview links opened while signed out get the stand-in. `stock.live_preview` turns it off.
- **Publishing is blocked** while a page holds a stock preview that isn't licensed: "The hero image is a Getty preview, not licensed yet. License it, or choose another image, before publishing.", shown on the image field. Saving unpublished still works. `stock.on_publish` (or the settings screen) can make it a warning instead.
- **Stock images** screen (a Ghostwriter sub-page): tabs Previews (requested licences first), Licensed, Failed and All, with where each image is used, its state, cost, licence and credit; License, Reconcile, Remove preview and the licence record; and a CSV export. The Overview shows "N stock previews to license" (amber when one is on a live page or its preview expired), and the widget says so in a line.
- `ghostwriter:stock-cleanup`, hourly on the scheduler: deletes previews when the library's preview period ends (the stand-in stays), removes stand-ins no page has used for 30 days, and settles licences whose outcome wasn't known.
- [Stock photos](docs/stock-photos.md) in the docs.
- A **License stock images** permission, given to nobody by default (super users have it), since licensing spends from the site's account.
- Where each ledger image is used is kept up to date as entries are saved (inside Replicator and Bard sets too), as assets are moved or renamed, and when an asset is deleted. `php please ghostwriter:stock-usages` rescans every entry.

- **Shutterstock**, licensed from your own API plan: set `SHUTTERSTOCK_API_KEY` and `SHUTTERSTOCK_API_SECRET`, then **Connect account** on the settings screen (sign in with Shutterstock; **Disconnect** to forget it). The connection's tokens are kept encrypted with the app key. Shutterstock previews are never stored: editors see Shutterstock's own watermarked preview. On a local site it uses Shutterstock's sandbox (`stock.shutterstock_sandbox`). If the connection is lost, licensing says so, with **Connect again in Settings**.

### Changed
- **Drafts start unpublished.** **Use this draft** on a new entry, or one that isn't published, switches the form's Published toggle off, so the entry can be saved straight away and goes live only when someone switches it on. An entry already published is left as it is. Config `drafts_unpublished` (default `true`).
- Requires `1994/ghostwriter-core` 1.2.
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
