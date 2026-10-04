# Suggest edits and Content to revisit

**Content to revisit** ranks your published pages by checks that need no AI: dates, links, alt text, empty fields and age. It tells you which pages are worth a look, and why.

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

## Settings

In **Ghostwriter → Settings**, under **Suggest edits and Content to revisit**. Each can be set in `config/ghostwriter.php` instead, which locks it on the screen.

| Setting | Default | Config |
| --- | --- | --- |
| **Check claims and counts**: flag counts and claims about you ("a team of 6", "over 20 years", "award-winning") on older pages as facts to check | On | `suggest.claims` |
| **Check links to other sites once a week** | Off | `revisit.external_links` |
| **Count age in full in these dated collections** | None | `revisit.age_in_full` |

`suggest.language` sets the language the phrase checks read (`en`, `de`, `fr`, `nl` or `es`); by default each site's own. `edit_reviews_path` and `revisit_path` move where reviews and the list are kept.
