# Changelog

## Unreleased

Requires `1994/ghostwriter-core` ^1.8.3 (resolving a gap from its chip, `Gaps\MarkerResolver`; since ^1.8.2 the gap chips, `resources/js/preview/markers.js`; layouts, extras and counts to check since ^1.8; and since ^1.7 the page preview's markers, block map and locator).

### Added
- **The page preview's rendering.** `POST sessions/{id}/preview` renders the unsaved draft through the site's own templates with Statamic's Live Preview: exactly the data **Use this draft** would put into the form, with invisible markers in the preview's copy only, so the panel can map the page back to its blocks. A new entry is an unsaved `PreviewEntry` with a preview-only id and the URI it will have (in a structured collection: its parent's plus its slug); an entry being edited is a copy with the draft over it. Nothing is saved: no entry, no placeholder image, and the session isn't marked as used. Renders are reused for an unchanged draft (by the preview data's hash), a few per piece for ten minutes; older tokens, and a deleted piece's, are deleted.
- **Ghostwriter's preview responses** (only those) get a Content-Security-Policy that blocks third-party scripts and their beacons, added beside any policy already there (Statamic's multisite `frame-ancestors` included); `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow`, `Cache-Control: private, no-store` and `X-Frame-Options: SAMEORIGIN`. Templates can test `{{ live_preview:ghostwriter }}`. Tags that load the current entry by id (`collection:next`, `previous`, `older`, `newer`) work for an entry never saved. A template that fails shows a short page the panel reads, rather than an error page. New config: `preview.enabled`, `preview.script_hosts`, `preview.timeout`.

- **The Preview tab.** The writing panel's draft has three tabs, **Preview | Blocks | Text**, with Preview first once there is a draft, for new entries and for edits. It shows the draft rendered through the site's own templates in a sandboxed, same-origin frame (**Preview · not saved**), with **Desktop** and **Phone** widths. It renders again after changes (debounced, and only while showing), loading the new page behind the old one, dimmed, and keeping your place. Hovering outlines each block with its name, Bard sets and rich-text sections dashed inside theirs; the outlines are measured again on resize, scroll, image and font loads. Links on the page do nothing. A page that fails to render says why, with **Show blocks instead** and **Try again**, and the piece opens on Blocks next time; a slow one keeps the last version. The tabs work by keyboard (arrow keys), the frame has a title, and the outlines are hidden from screen readers. See [The Preview tab](docs/writing.md#the-preview-tab), and [for site developers](docs/writing.md#the-preview-for-site-developers) on `{{ live_preview:ghostwriter }}`.
- Bard sets are mapped on the preview as blocks of their own, inside the section they sit in, so an image-only set is found by its file name.
- **Layout cards.** Above the draft, up to three cards under **Layout**: the draft as written and the other layouts of the same words, each with a live thumbnail of the page (the preview's render for that layout, scaled down, with no scripts, starting where the layouts differ; reused while the draft is unchanged), its name, a line about it, its block count and **Suggested** on the one most like the collection's pages. Choosing one changes Preview, Blocks and Text, and the button reads **Use this draft (Numbers first)**; the choice is the piece's, shared with everyone on it. Skeleton cards say "Finding other layouts…" while the planner looks; a layout an edit left behind says "Needs refreshing", with **Refresh layouts**. With one layout there is no row; without a preview, cards list their blocks. The cards are a radio group (arrow keys), and scroll sideways on a phone. See [Layouts](docs/writing.md#layouts).
- **Finish this page: counts to check** (core's `GapKind::Check`). A number Ghostwriter counted from a list you gave (`[[check: 3 areas | from: …]]`) is a step in the guide: "I counted 3 areas from “…”. Is that right?" with **Looks right**, **Change it** (the count, editable in the guide) and **Remove it**; when the list has changed since in your answers, messages or the draft, "…It now has 4. Use “4 areas” instead?" first. It's in the count by Save, tagged "Check me", underlined in Bard, and blocks publishing ("Check “3 areas” before publishing.") or warns, like a fact to add. The check, apply and the publish guard pass the piece's sources (`GapContext::$sources`, from the session the form came from or the entry's own). See [Counts to check](docs/finish-this-page.md#counts-to-check).
- **Extras in the Text tab.** The extras the writer prepared, each with its source ("from your answer · question 1", "from the draft", "from About" linked to the entry, "your words"), "Needs review" and "Counted from your answer: …" on a number Ghostwriter counted, and "Needs your answer" on one waiting for a fact. Every part is editable in place, and ✕ deletes an item; neither calls a model. Editing a stat's label keeps its count to check. See [Extras](docs/writing.md#extras).
- **Layouts and extras, on the server** (core's `Arrange\SessionLayouts`; always on, no setting). The writer now prepares extras with the draft (stats, FAQs, a pull quote and the like, only for the block types the blueprint has, and only from what you told it, the draft or the entries it was shown). The first draft's turn saves the draft with its own layout at once, then makes **one** call to the layout planner for up to two other layouts of the same words, checked against the blueprint and ranked against the collection's own pages ("Suggested"); the panel shows the draft meanwhile. Later turns, hand edits and Edit YAML re-arrange the layouts without a call; one that no longer fits is marked as needing a refresh. New routes: `PATCH sessions/{id}/layout` (choose; stored on the session, so shared), `POST sessions/{id}/layouts/refresh` (one planner call, queued), `PATCH`/`DELETE sessions/{id}/extras/{item}`. A session's JSON gains `layouts` (the cards) and `extras` (with their source labels and states). **Use this draft**, the preview and the Blocks and Text views use the chosen layout; with the writer's own chosen, apply is exactly as before. The preview takes a `plan` for a card's thumbnail.

- **Gap markers shown as chips, not raw text** (core's `markers.js`, copied as `resources/js/preview/markers.js` with a checksum test, as the locator is). **In the Preview tab** and the layout cards' thumbnails, after the locator has placed the blocks, `[[ask: …]]` is an amber chip reading the hint ("Only you know this: add it before publishing"). A count to check is its value with a dotted amber underline ("Counted from '…'. Check it before publishing"), and a `#gw-link:` link has a dashed underline ("Link to choose"). Each chip has a hidden label for screen readers, and its styles go into the frame, never the site's CSS. The frame's bar shows the title's markers as their words. **In the Extras list**, asks and counts to check are chips, and clicking one to edit shows the words as stored, markers included. **Under plain text inputs** on the publish form (text fields, Grid and Table cells), a row of chips names each gap ("Add: adult ticket price", "Check: 3") as you type. The field's own highlight and tag stay. All of it is display only: the draft, the extras and the saved entry keep their markers exactly. See [The Preview tab](docs/writing.md#the-preview-tab).

- **Deal with a gap from its chip, in the draft.** Clicking a chip in the Preview (or Tab to it and Enter) opens a small dialog at the chip, over the page: a fact to add takes your answer (**Add it**, **Leave it for later**); a count to check has **Looks right**, **Change it** and **Remove it**; a link to choose lists the pages its hint suggests, or **Choose an entry** finds one by title. The answer goes into the stored draft exactly as typed, with core's `Gaps\MarkerResolver` (no model), saved like any draft edit under the piece's lock; the layouts re-arrange without a call, and the Preview, Blocks and Text follow. A gap dealt with here never reaches the form, so Finish this page doesn't list it after **Use this draft**; one left for later still shows. A link in a field the draft doesn't hold (a button's link, which the house style fills when the page is built) is kept in the draft by its hint (`gw_links`) and put in wherever its sentinel turns up, under any layout. The dialog moves focus into it, keeps Tab inside, closes on Esc and puts focus back on the chip. New routes: `PATCH sessions/{id}/gap` and `GET sessions/{id}/links`. See [The Preview tab](docs/writing.md#the-preview-tab).
- **Chips in the Text tab.** Writing in the Text tab shows its markers as chips, which open the same dialog. Clicking its words (or Enter, or typing) starts editing it as stored, raw markers and all, so nothing saved can hold a chip's markup (`textchips.js`; core's `unmarkGaps()` to be sure). The Extras list works the same way.

### Changed
- The writing panel stacks the conversation and the draft on a phone without overlapping: the composer no longer covers the draft's tabs and buttons, the layout cards or the Preview's bar (checked at 320, 375 and 414 px, light and dark). The Preview's badge reads **Not saved** in a frame narrower than 360 px.
- The page preview now prints a count still to check as its marker, which the panel shows as a chip with its value (it used to print only the value). The extras in a session's JSON (`text`, `parts`) are as stored, markers included, for the Extras list's chips.
- Apply's mapping is now `Drafts\DraftValues`, shared with the preview; apply's results are unchanged.
- A new page in a collection whose kind is set to one blueprint previews again: reading the collection's entries no longer leaves the preview without its collection.
- A preview is reused for the same draft even when the build gives new page-builder sets new IDs, so a page-builder draft (and each layout card) no longer renders afresh on every request. Up to eight renders are kept per piece.

### Fixed
- **Editing an entry in conversation keeps the sets in its Bard fields.** The draft only holds a Bard field's pull quotes, so applying an edit used to drop its other sets (images, stats and the like). Each now goes back after the words it followed, wherever a chosen layout put them; where those words were rewritten, back in its own field at about the same place, or at the end of a field the draft made shorter.

### Removed
- **The Images section under a draft.** The writing panel no longer lists the draft's image fields with **Find a photo**, **Make the picture** and **Use the same image as**. It duplicated what happens on the form: **Use this draft** still puts the striped placeholder in each empty image field, and **Finish this page** turns each into a gap with **Find a photo** and **Choose from Assets** on the field, with stock libraries, previews and licensing. Asking in the conversation to add or make images still works, and those go into the form with the draft. The writer no longer searches for photos to offer with every first draft. Gone with it: `GET`/`POST sessions/{id}/photos`, `POST sessions/{id}/images` and `POST sessions/{id}/images/copy`, and `image_tools` and the photo options in a session's JSON. See [Images with a draft](docs/images.md#images-with-a-draft).

## 1.2.0 - 2026-10-03

Requires `1994/ghostwriter-core` ^1.6.1, which no longer brackets quoted titles or figures you gave in a filled-in brief.

Requires `1994/ghostwriter-core` 1.6.

### Added
- **OpenRouter, a fourth provider.** Choose **OpenRouter** under Settings → AI provider to write (and make images) with Claude, GPT, Gemini and others through one OpenRouter account, paid for with OpenRouter credit. **Connect with OpenRouter** signs in and comes back with a key, kept encrypted in `storage/ghostwriter/provider-keys.json`; or set `OPENROUTER_API_KEY` in `.env`, which always wins. The settings row offers Connect, Disconnect and **Check connection** (the credit left), a model for writing and one for quick jobs (`openrouter.models.*` in the config), and says that requests pass through OpenRouter. Only people who may change the settings can connect. See [API keys](docs/api-keys.md#openrouter).

### Changed
- **The brief is in the conversation.** The brief screen is gone. Once you've chosen what you're writing, Ghostwriter asks for the quick details in one message ("What’s it called, and what should it say? A line or two is plenty."), fills in the kind's whole brief from your reply with one model call, and shows it as a brief card: the working title, every question with its answer and **Model it on**, all editable. **Looks right, start writing** stores the brief on the piece and starts writing; **Try again** fills it in again, keeping the answers you changed. Anything only you know stays in `[square brackets]` (they become gaps in the draft for Finish this page); figures and quotes you didn't give are never filled in. The writing then goes on as before, and the agreed card collapses to **Show the brief**, still editable (**Save the brief** runs no turn). **Draft this** on the content plan skips the question: the card arrives filled in from the idea. Pieces carried on and shared conversations show the card in the thread; pieces started before keep their brief behind **Show the brief**. The card is a labelled region, its arrival is announced to screen readers and takes the keyboard to it, and spinners stand still under reduced motion. A kind looked at and left keeps nothing: the piece is saved once you send the details. See [Writing a new entry](docs/writing.md#the-brief).
- Core's reworded gap messages ("Only you know this") come from core; the addon's own copy of them is gone.

### Removed
- The brief screen's **Fill in the brief** and its route (`types/{type}/brief`). `POST types/{type}/sessions` now opens a piece (with `details` or `idea`) instead of taking the answers.

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
