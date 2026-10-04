# Suggest edits and Content to revisit

**Suggest edits** reads an existing page against your voice guide and the rest of the site, and suggests small changes for you to step through: accept, edit, try another version or dismiss each one. **Content to revisit** ranks your published pages by checks that need no AI (dates, links, alt text, empty fields and age), so you know which pages are worth a review, and why.

## Suggest edits

On an existing entry, open the menu beside **Edit with Ghostwriter** and choose **Suggest edits**. A confirm says what it does and what it costs before anything is sent:

- **Two calls to your AI provider** for a page: one reviews the page, a second double-checks every suggestion before you see it. A long page is read in parts, and the confirm says how many calls that makes.
- How many things the free checks found already, to be checked in context.

The review runs in the background (on the queue). While it runs, the pill beside Save reads **Reviewing…** and the guide shows its progress. You can keep working on the page.

### What it suggests

Free checks find candidates for nothing: a past year written as current ("New for 2024"), "this year" on an old page, a count or claim about you that may have changed ("our team of 6 designers"), a sentence that runs on, a link to a deleted page, an image with no alt text, and an SEO title or description over its limit. **None of these reaches you on its own.** The review reads each one in its paragraph, under its heading, with the page's title, kind and date and your voice guide, and keeps it only if it's a real problem there ("New for 2023" in a 2023 journal post is history, so it's dropped). It adds its own suggestions for voice and clarity on the same terms. A second call then checks every suggestion again: that it's real, that the new words read naturally in their sentence, that they add no facts, and that they're in your voice. Things judged fine are remembered, and aren't suggested again until their sentence changes.

| Category | What you can do |
| --- | --- |
| **Out of date** | The whole sentence rewritten, with up to two other versions (one without the dated words). **Accept**, **Edit**, **Another version**, **It's still right** (not asked again for 12 months, or until the sentence is edited), **Dismiss**. |
| **Voice**, **Clarity**, **SEO**, **Duplicate** | **Accept**, **Edit**, **Another version**, **Dismiss**. Once the two free versions are used, **Write another** asks for more (one small call, and it says so). |
| **Fact to check** | Ghostwriter never supplies a fact. Type the answer ("Number of designers": 8) and **Use it**, or **It's still right**, **Remove the number**, **Dismiss**. "8 or 9?" is refused: type the number only. |
| **Link** | **Link to it** (the page it found), **Choose an entry** (the field's own picker), **Remove the link** (the words stay), **Dismiss**. |
| **Accessibility** (alt text) | **Save to the image**, after a confirm: alt text belongs to the asset, so it's saved now, not when you save the page, and shows wherever the image is used ("2 pages"). **Undo** writes the old alt text back. Without permission to edit the asset's container, you get the text to **Copy**. |

Each step shows the change **in its sentence**, the old words struck through and the new ones below, with the reason and where it comes from ("Voice guide: “Words we never use”").

### Stepping through

The guide is Finish this page's: the pill by Save, the flying mark, the dock and the highlights, in indigo so a suggestion never looks like something unfinished (amber). Fields with suggestions are outlined and tagged ("2 · Voice", "3–4"); inside Bard the words have a dotted underline, the current ones filled.

- **Filters**: All, then one per category, with how many are open.
- **Accept all wording fixes** accepts every open Voice, Clarity and SEO suggestion in the filter (never facts, links, alt text or dates). **Undo all** puts them back.
- **Undo** after any decision puts the words back.
- Everything goes **into the form**. Nothing is saved until you save (alt text aside). When you save, suggestions whose words are in the page are marked done.
- Decisions are shared with everyone who can edit the page (as conversations are) and kept as the page's history. Suggestions nobody acted on expire after 14 days; the decisions stay.
- A review opened later shows its pill with the guide minimised. If you edit text a suggestion was about, it's marked "This text has changed since the review."
- **Keyboard**: Alt+Shift+N and P step through, Alt+Shift+G opens or minimises, Esc minimises from inside the guide, Enter puts in an answer or your edit and Esc puts it back. Changes are announced to screen readers.
- Below 640 px the guide is a sheet at the bottom of the screen.

## Content to revisit

### What it checks

Every published entry in a collection Ghostwriter writes for is checked, for nothing: no model is ever asked.

| Reason | What it means |
| --- | --- |
| **“New for 2024”** | A past year written as if it were current. History ("since 2015", "in 2019 we won") is left alone. |
| **“this year” = 2023** | "this year", "next spring", "currently", "coming soon" on a page last saved a year or more ago. |
| **closing date passed** | A date after a closing word ("applications close 31 January 2025"), or a date field such as `closing_date` or `deadline`, now past. |
| **1 broken link** | A link to an entry or asset that has been deleted. |
| **1 link to another site failed** | Only when the weekly check of links to other sites is on (below). |
| **no alt text** | An image whose asset has no alt text, where its container's blueprint has an `alt` field. |
| **empty fields** | Fields most pages like it fill, left empty. |
| **unfinished** | A fact to add, a link to choose or a placeholder left from [Finish this page](finish-this-page.md). |
| **SEO text too long** | An SEO title over 60 characters or a description over 160 (or the field's own character limit). |
| **2 years old** | Age, from the entry's last save. |

The phrase checks read English, German, French, Dutch and Spanish. A site in another language gets the checks that need no words: links, alt text, SEO length, empty fields and age.

**Dated collections.** In a collection with dates (a journal, news), an old post is meant to be old, so age and past years count a quarter as much. A manager can make any dated collection count them in full.

### Keeping it current

- **On save.** Saving an entry queues its row again (at most once a minute per entry). Unpublishing it takes it off the list.
- **On delete.** Deleting an entry checks again every page that linked to it, so the broken link shows at once.
- **Daily** at 03:00, on Laravel's scheduler: `php please ghostwriter:revisit` reads entries saved since the last run (a change made outside the Control Panel, such as a Git pull, is caught by the full pass) and the pages whose day has come (the day after a closing date, 1 January for "New for 2026"). Once a week, and the first time, it reads every page. `--full` reads every page now.
- **Weekly**, Sundays at 04:00: `php please ghostwriter:check-links`, which does nothing unless the check of links to other sites is on.

The list is kept in JSON files beside the sessions, one per site and collection: `storage/ghostwriter/revisit/{site}/{collection}.json`. Nothing goes in a database.

### Links to other sites

**Check links to other sites once a week** is off by default, and only someone who can change Ghostwriter's settings can turn it on. When it's on, once a week Ghostwriter asks each site your pages link to whether the page is still there:

- a HEAD request (a one-byte GET if the site refuses HEAD), saying it's Ghostwriter's link check;
- each address at most once a week, however many pages link to it, and at most 500 a run;
- one request at a time, at least a second apart on any one site, with a 10-second timeout.

A link counts as broken only after it fails twice in a row (404, 410, or a name that no longer resolves). Anything else (a timeout, 401, 403, 429, a server error) says nothing about the page and is never shown. Links to your own pages are always checked, with no request.

### Review

**Review** on a row of the list opens the page with Suggest edits ready to run: the confirm, with its cost, or a review that still fits the page.

## Settings

In **Ghostwriter → Settings**, under **Suggest edits and Content to revisit**. Each can be set in `config/ghostwriter.php` instead, which locks it on the screen.

| Setting | Default | Config |
| --- | --- | --- |
| **Check claims and counts**: flag counts and claims about you ("a team of 6", "over 20 years", "award-winning") on older pages as facts to check | On | `suggest.claims` |
| **Check links to other sites once a week** | Off | `revisit.external_links` |
| **Count age in full in these dated collections** | None | `revisit.age_in_full` |

`suggest.language` sets the language the phrase checks read (`en`, `de`, `fr`, `nl` or `es`); by default each site's own. `edit_reviews_path` and `revisit_path` move where reviews and the list are kept.
