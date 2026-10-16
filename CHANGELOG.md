# Changelog

## 1.3.0 - 2026-10-16

Suggest edits and Content to revisit, which find what has gone stale on your published pages and help you fix it; a live preview of every draft through your own templates, with other layouts to choose from and comments you pin on the page; drafts that arrive linked to your other pages, with a search title and description; and **Connections**, one place to set up every key. Requires `1994/ghostwriter-core` ^1.10, Statamic 6.30 or later and PHP 8.3 or later.

### Added
- **Suggest edits**, in the menu beside **Edit with Ghostwriter** on an existing entry. Free checks look for candidates: a past year written as current ("New for 2024"), "this year" on an old page, a count or claim about you that may have changed ("our team of 6"), a sentence that runs on, a link to a deleted page, an image with no alt text, SEO text over its limit or missing, a heading over 70 characters, and a long page with no links to your other pages. A confirm says what the review costs before anything is sent. The review keeps a candidate only if it's a real problem in its paragraph, adds its own suggestions for voice and clarity, and a second call checks every suggestion again before you see it. The guide shows each change in its sentence, with the reason and where it comes from, and **Accept**, **Edit**, **Another version**, **It's still right** and **Dismiss**, with Undo; category filters; **Accept all wording fixes**; links to your other pages; and alt text saved to the image, after a confirm that says how many pages use it. Accepted words go into the form; nothing is saved but alt text. Decisions are shared and remembered. See [Suggest edits](docs/suggest-edits.md).
- **Content to revisit** (Ghostwriter → Content to revisit, and a tile on the Overview): every published page in the collections Ghostwriter writes for, ranked by free checks (no AI): past years written as current, a closing date that has passed, broken links, images without alt text, empty fields that pages like it fill, unfinished markers, SEO text over its limit, no SEO description, no links to your other pages, heading levels, and age. Tiles filter the list; **Review** opens a page with Suggest edits ready to run; **Snooze for 90 days**; a collection filter. Only the collections you can view are listed. It's kept up to date as entries are saved and deleted, and by `php please ghostwriter:revisit`, which runs daily at 03:00 on Laravel's scheduler (a full pass weekly).
- **A weekly check of links to other sites**, off by default. Turned on in settings, it runs on Sundays at 04:00: each address at most once a week, one request a second per site, and a link counts as broken after two failed checks in a row.
- Three settings, under **Suggest edits and Content to revisit**: **Check claims and counts** (on), **Check links to other sites once a week** (off) and **Count age in full in these dated collections** (none). Each can be set in `config/ghostwriter.php` instead (`suggest.claims`, `revisit.external_links`, `revisit.age_in_full`).
- **The Preview tab.** The draft now has three tabs, **Preview | Blocks | Text**. Preview shows the unsaved draft rendered through your site's own templates, at **Desktop** or **Phone** width, and renders again as the draft changes. Hovering outlines each block with its name. Nothing is saved to show it. A template that fails says why, with **Show blocks instead** and **Try again**. Preview pages get headers that keep them private and out of search engines and block third-party scripts. Templates can test `{{ live_preview:ghostwriter }}` to leave things out of Ghostwriter's previews. New config: `preview.enabled`, `preview.script_hosts`, `preview.timeout`. See [The Preview tab](docs/writing.md#the-preview-tab).
- **Layouts.** With a first draft, Ghostwriter offers up to two other layouts of the same words, ranked against your collection's own pages, with the most like them marked **Suggested**. They show as a row of names above the draft; **Compare layouts** shows them as cards with a thumbnail of each page. Each says what it changes ("Quote moved up · Text blocks joined"), and choosing one scrolls to and outlines what changed. Only layouts that really differ are offered. The choice is shared with everyone on the piece. See [Layouts](docs/writing.md#layouts).
- **Extras.** The writer prepares stats, FAQs, a pull quote and the like, only for the block types your blueprint has and only from what you gave it. The Text tab lists each with where it came from, and every part can be edited or deleted. See [Extras](docs/writing.md#extras).
- **Comments on the page.** Turn on **Comment** above the Preview (Alt+Shift+C), click a block or select words, and write a comment. Comments stay yours, in your browser, until you **Apply** them: one call revises only the commented blocks, and the reply says what happened to each (Changed, with **Before / after** and **Put it back**; Replied; Not applied; or Skipped because someone else changed the block). Comments can be resolved and reopened, follow their words between layouts, and work by keyboard. See [Comments](docs/writing.md#comments).
- **Gaps as chips in the draft.** In the Preview and the Text tab, a fact to add, a count to check and a link to choose show as small chips instead of raw markers. Click one to deal with it there: type the fact, confirm or change the count, or choose the page to link to. Under plain text fields on the publish form, a row of chips names each gap as you type. The saved entry keeps its markers until they're dealt with.
- **Questions before a draft, one box each.** When Ghostwriter needs to ask, it lists at most four short questions, each with its own box (or choices), *(optional)* where it is, and **Skip**. **Send answers**, or **Just draft it with what you have**.
- **Model it on picks entries for you.** In a collection with no learned kind, the brief now arrives with up to six published entries ticked, the closest in purpose and shape to the new piece. Your own ticks always win.
- **Links to your other pages in every first draft.** Ghostwriter links a new draft to the most relevant pages of your site (about one link per 250 words, two to five), and a second call checks each one. Links Ghostwriter added are marked in the Text and Blocks tabs, where you can see where each goes and **Remove link**. Every routed collection and taxonomy term page can be linked to; drafts, noindex pages, utility pages and other sites never are.
- **Suggest links** in Finish this page: **Link to your other pages** finds pages to link to on the page as it stands, in the background, and offers each as a step: "Link “…” to Contact us?", **Link it** or **Skip**.
- **A search title, description and address with the first draft.** Ghostwriter writes the page's SEO description, and a search title when the page title is too long, from what the page says. They're in a **Search** section at the end of the Text tab, editable, with a live character count and **Try again**. **Use this draft** puts them into SEO Pro or your plain SEO fields, never over text someone wrote, and fills the slug on a new or unpublished entry. Photo file names are made from the alt text.
- **Headings fit your template.** Drafts start at H2 under the page's H1, never skip a level, and use only the heading levels each Bard field allows. The Preview reads how your template prints headings, and super users see a note on the Overview when a template prints no H1, or several.
- **Finish this page** gains:
  - **counts to check**: a number Ghostwriter counted from a list you gave ("3 areas") is a step, with **Looks right**, **Change it** and **Remove it**, and blocks publishing like a fact to add;
  - **Shorten a heading** for a heading over 70 characters (**Write it for me**, one small call);
  - **Link to your other pages** on a page of 300 words or more with no links to your site;
  - **Add a description for search** when the SEO description is empty or too short;
  - a prompt for an image the page looks like it needs (required, the hero, or one most pages in the collection have).

  These suggestions never block publishing, except counts to check.
- **Connections** (Tools → Ghostwriter → Connections), for managers: a card for every service Ghostwriter uses, under Writing (Anthropic, OpenAI, Gemini, OpenRouter), Images (Unsplash, Pexels, Pixabay, Openverse) and Stock photos (Shutterstock). **Set up** opens the page where the key is made and takes you through it; **Check & save** tests the key with one small call before keeping it. Each card says whether it's connected (showing only the key's last four characters), set in `.env`, or has stopped working, with **Replace key** and **Disconnect**. Connect with OpenRouter and Shutterstock's Connect account are on their cards. A key in `.env` or config always wins. See [Connections](docs/connections.md).
- Keys set up in Connections are encrypted with `APP_KEY`, in a new `ghostwriter_credentials` table (`php artisan migrate`), or in an encrypted file without a database. If `APP_KEY` changes, set them up again.
- A per-person limit on starting work that calls a model: 30 a minute by default (`rate_limit`, or `GHOSTWRITER_RATE_LIMIT`; `0` turns it off).
- German, French, Dutch and Spanish for the new SEO and Connections wording.

### Changed
- **The publish guard only acts on what an editor publishes in the Control Panel.** Saves from console commands, imports, queued jobs and other addons are never refused. In collections Ghostwriter doesn't write for, it holds back only a stock photo previewed but not licensed, so an old link to a deleted page no longer stops a save there.
- **Ghostwriter only reads entries you can view.** Learning or suggesting kinds, writing for a collection, and the entries listed under **Model it on** need Statamic's permission to view that collection's entries; collections you can't view aren't offered.
- **Remove preview** on the stock images list needs permission to delete assets in the preview's container.
- When a draft's preview fails, only people signed in to use Ghostwriter see the error's details; anyone else with the link sees a plain line.
- **One menu beside Edit with Ghostwriter.** The "things to finish" and "suggestions" pills are replaced by one count on a menu holding **Finish this page**, **Review suggestions** and **Suggest edits**.
- **The writing panel works at every width.** Below about 900 px the conversation and the draft are one column, with **Conversation | Draft** at the top, and nothing in the panel covers anything else. The draft scrolls as one page.
- **The writer keeps its own links to real pages** instead of turning them into links to choose. For a link it couldn't place, Finish this page suggests the page it most likely means first.
- **Better link suggestions**: pages are matched by word stems in English, German, French, Dutch and Spanish ("seed" meets "seedheads"), rare words count for more, key pages are always considered, and pages that link to each other or share a category rank higher.
- **Finish this page leaves required fields to Statamic's own validation**; an empty required field is no longer a step.
- Addresses keep the whole title ("what-to-do-in-the-garden-in-march"); only a title over about 60 characters loses its little words.
- Suggest edits shows only what to act on, never how many things were found fine.
- Settings' API keys section is now a summary linking to Connections. The OpenRouter key and Shutterstock account move into Connections by themselves, and their old files are removed.

### Fixed
- Editing an entry in conversation keeps the sets in its Bard fields (images, stats and the like) instead of dropping them.
- Ghostwriter's note to the writer when editing an entry no longer shows in the conversation as if you'd sent it.
- SEO Pro descriptions and titles are read as SEO Pro prints them: the entry, then the collection's defaults, then the site's, including `@seo:` fields, Bard sources and noindex.
- An entry removed without an event (its file deleted, a deploy) is forgotten by the weekly pass.
- "1 thing still to finish" always opens onto that thing.
- A link to choose whose hint has a space is listed in Finish this page.
- The guide's mark points at the words, not the field, and its words never cover the page's text or run off the window. Long button labels are cut short inside the card.
- Suggest edits' spinner sits beside its words.
- Teach a kind's name box is labelled for screen readers.

### Removed
- **The Images section under a draft.** **Use this draft** still puts a placeholder in each empty image field, and Finish this page turns each into a step with **Find a photo** and **Choose from Assets**. Asking for images in the conversation still works.

### Upgrading
```bash
composer update 1994/ghostwriter-statamic 1994/ghostwriter-core
php artisan vendor:publish --tag=ghostwriter-statamic --force
php artisan migrate
php artisan ghostwriter:revisit --full
php artisan queue:restart
```

- `migrate` makes the `ghostwriter_credentials` table for Connections. Without a database, skip it: keys are kept in an encrypted file.
- Keys in `.env` keep working. The OpenRouter key and Shutterstock account move into Connections by themselves.
- `ghostwriter:revisit --full` builds Content to revisit and the link index straight away; otherwise they fill in over the daily pass. Make sure Laravel's scheduler runs (`php artisan schedule:run` every minute).
- See [Upgrading from 1.2 to 1.3](docs/installation.md#upgrading-from-12-to-13).

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
