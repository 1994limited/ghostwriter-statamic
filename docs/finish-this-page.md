# Finish this page

Some things only a person can finish: the price of a ticket, where a button should go, the photo for the hero. Ghostwriter never makes these up. It marks the place instead, and **Finish this page** finds every mark in an entry, highlights it on the entry's form and walks you through it. A page can't be published while something still needs you.

![The guide on a journal entry: the count on the Ghostwriter menu, the Body field outlined and tagged "2 · Needs a link", the fact to add and the link to choose marked in its text, the Ghostwriter mark at the words, and the guide offering Link to Contact, Choose an entry and Remove the link](images/finish-this-page.png)

It works on any entry in a collection Ghostwriter writes for, whoever wrote it.

## What it finds

| What | How it looks in the entry | Blocks publishing |
| --- | --- | --- |
| **A fact to add** | `[[ask: adult ticket price]]` in the text. Ghostwriter writes this wherever a draft needs a figure, a date, a name or a quote it wasn't given. | Yes |
| **A fact for a number or date field** | The field is empty, and the draft asked for it. | When the field is required |
| **A count to check** | `[[check: 3 areas \| from: Northumberland, Durham and the Tyne Valley]]`: a number Ghostwriter counted from a list you gave, in an [extra](writing.md#extras) such as a stat. See [Counts to check](#counts-to-check). | Yes |
| **A link to choose** | A link whose address is `#gw-link:contact-page`, in Bard or Markdown, or a Link field holding `#gw-link:…`. The words stay; only where it goes is missing. | Yes |
| **A link to a page that's gone** | A Link field, an Entries field or a Bard link pointing at an entry that has been deleted. | Yes |
| **An image placeholder** | Ghostwriter's striped placeholder (`ghostwriter/image-placeholder.png` in the field's container), in an assets field or inline in Bard. See [Images](images.md#placeholders). | Yes |
| **A stock preview not licensed** | A paid library's preview. See [Stock photos](stock-photos.md). | Yes |
| **Template text** | A placeholder such as `[[item]]` left in the text. | Yes |
| **An image the page looks like it needs, left empty**: it's required, it's the page's hero (the set the template prints the H1 from, or a hero image when there are too few published entries to go by), or at least 70% of the collection's 20 newest published entries have one. The step says which: "Hero image is required. Add one?", "Hero image is empty, but most Journal entries have one. Add one?" | | No: it's counted and brings the guide out, but only Statamic enforces required. Not on a new entry until a draft is put in or it has some text. |
| **A link field left empty** that most entries like this fill | | No: it's counted, not enforced |
| **A field most entries fill, left empty**, and text such as `TBC`, `[insert date]` or `lorem ipsum` | | No: a suggestion only |
| **Links Ghostwriter added** to your other pages, in a draft you used. See [Links Ghostwriter added](#links-ghostwriter-added). | A normal link to an entry | No: a suggestion only, never counted |
| **A long heading**: a heading in Bard or Markdown over 70 characters. See [Search: headings and links](#search-headings-and-links). | | No: a suggestion only, never counted |
| **No links to your other pages** on a page of 300 words or more. See [Search: headings and links](#search-headings-and-links). | | No: a suggestion only, never counted |
| **A description for search** that's empty, or shorter than search results have room for (under 120 characters for a limit of 160), in SEO Pro or a plain `meta_description` field, on a page someone has worked on. See [A description for search](#a-description-for-search). | | No: a suggestion only, never counted |

A required text, date or select field left empty isn't listed: Statamic's own validation says so when you save.

Everything is found from the entry's values and its blueprint, as you type. Finding them never asks a model and never saves anything.

### The markers

- `[[ask: …]]` is plain text, so it survives every field: Bard, Markdown, text and textarea. It shows on the page in Live Preview, on purpose. Type the fact over it, or use the guide.
- `#gw-link:` is a real link to a place on the same page, so if one were ever published it would go nowhere rather than to a wrong page. Choose an entry for it, or remove the link and keep the words.
- `[[check: … | from: …]]` holds a count and the list it was counted from. It shows on the form as written, and in the panel's preview as the number alone.
- No keyword is translated. The words inside are in your site's language.

## On the entry's form

When an entry has something to finish, a count appears on the Ghostwriter menu, beside **Edit with Ghostwriter** (or **Write with Ghostwriter** on a new entry): amber, as it stops the page going live. The same count includes any [suggested edits](suggest-edits.md) to review, so it's one number for everything waiting. The fields are highlighted, and the menu's **Finish this page**, with its own count, opens the guide:

- **amber**: still to do (or skipped for now);
- **purple**: the one the guide is on;
- **green**: fixed since you opened the page.

Each highlighted field has a numbered tag ("2 · Needs a link"); click it to go to that step. Inside Bard, the marker itself is underlined with a dashed line, so you can see exactly where the gap is.

A plain text box (a text field, or a cell of a Grid or Table) can't underline part of its text, so one holding a gap gets a row of small chips under it, one per gap: "Add: years trading", "Check: 3", "Choose a link: contact page". The field keeps its highlight and tag. The chips follow your typing, and they're never part of the value.

![A Grid's cells, each with a chip under it naming its gap](images/finish-grid-chips.png)

### The guide

Click the count, or the Ghostwriter button in the bottom corner, to open the guide. It shows one gap at a time: what's missing, and what you can do about it. It opens the field's tab, expands a collapsed set and scrolls to it, and the Ghostwriter mark flies over to point at it.

- **A fact to add:** type it into the box in the guide and press Enter. It replaces the marker in the field. Esc clears the box.
- **A link to choose:** first, the page Ghostwriter suggested for a link the writer left (**Link to Contact us**: picked when the draft was linked to your pages and checked by a second look, never put in for you); then **Link to Contact** when an entry's title or slug matches the link's hint, without repeating the suggested page; otherwise **Choose an entry** (the field's own picker, or type an address) or **Remove the link**, which keeps the words.
- **An image placeholder or an empty image:** **Find a photo** (Ghostwriter's image dialog for that field), **Choose from Assets**, or **Leave it empty** when the field isn't required.
- **A stock preview:** **License**, which opens the same License & replace step as the field's badge (or Request licence, without the permission), or **Choose another**.
- **A count to check:** see below.
- **Template text:** **Remove it**.
- **Links Ghostwriter added:** **Keep it** or **Remove the link**. See below.

![A new page after Use this draft: the Text set tagged "3 · Needs a link", the words "tell us about your garden" marked in it, and the guide on step 3 of 4 saying the link doesn't go anywhere yet, with Link to Contact first, then Choose an entry and Remove the link](images/finish-link-suggested.png)

### Counts to check

A stat such as "3 areas" may be counted from a list you gave ("Northumberland, Durham and the Tyne Valley"). Ghostwriter counts the list itself; the model never supplies the number. Before the page goes live, the guide asks you to check it:

> I counted 3 areas from "Northumberland, Durham and the Tyne Valley". Is that right?

- **Looks right** puts the count in place of the marker.
- **Change it** opens the count to edit, filled in: change it and press Enter (Esc goes back).
- **Remove it** takes it out.

If the list has changed since (in your answers, your messages or the draft), the step says so and offers the new count first:

> I counted 3 areas from "…", but that list has changed since. It now has 4. Use "4 areas" instead?

When no list like it is there any more, it asks whether the count is still right, with **Change it** and **Remove it**; when the number was edited by hand and no longer matches its list, it offers the list's own count. Each is counted on the menu, tagged "Check me" on its field, underlined in Bard like a fact to add, and [blocks publishing](#publishing) until it's resolved.

![The guide on a count to check: Looks right, Change it, Remove it](images/finish-check.png)

![The list changed since: Use "4" instead](images/finish-check-stale.png)

### Links Ghostwriter added

When a draft you used has [links Ghostwriter added](writing.md#links-to-your-other-pages) to your other pages, the guide ends with them, after anything still to finish:

> Check 2 links Ghostwriter added. "planting plan from us" goes to How a planting plan comes together (/journal/how-a-planting-plan-comes-together). Keep it, or remove the link and keep the words.

- **Keep it** leaves the link as it is and puts the step away.
- **Remove the link** takes the link out in the field and keeps the words.

It's marked as a suggestion ("A suggestion: it won't stop the page going live"), the field is tagged **Linked**, and it never counts on the menu or blocks publishing.

![The guide on a link Ghostwriter added: "planting plan from us" in a Text block tagged Linked, with Keep it and Remove the link](images/finish-links.png)

**Back**, **Skip for now** and **Next** move between gaps. A skipped gap stays highlighted and still counts. Every fix goes into the form only: nothing is saved until you press Save.

Press **—** (or Esc while you're in the guide) to tuck it away into the corner button, which shows the count; click it to bring the guide back. Ghostwriter remembers, for each person, whether they left the guide open. It starts tucked away, and opens by itself when you put a Ghostwriter draft into the form (`finish.open_after_draft`).

The count and the highlights follow your typing: the form is checked again a moment after you stop.

### A description for search

When the page's SEO description is empty or too short, the guide suggests **Add a description for search**:

> The SEO description is only 14 characters. Here's the one from the draft: "Winter care for gardens across Northumberland…"

- **Use this** puts the description from the draft you used (its [Search](writing.md#search) section's) into the field, in the field's own shape (SEO Pro's custom value, or the plain field's text). It costs nothing: the text was written with the draft.
- **I'll write it** takes you to the field.

Without a description from a draft, only **I'll write it** is offered. A description SEO Pro takes from another field that fits, a template and one switched off are left alone. It's a suggestion: it never counts on the menu or blocks publishing.

### Search: headings and links

Two more suggestions help the page in search. Neither counts on the menu or blocks publishing.

- **Shorten a heading.** A heading over 70 characters is hard to scan and gets cut off in search results. **Write it for me** asks Ghostwriter for a shorter one with the same meaning (one small call, and the button says so); only the heading's words change, in Bard or Markdown. **I'll write it** selects the heading for you.
- **Link to your other pages.** A page of 300 words or more with no link to your site's own pages (a link to an entry, a path such as `/contact`, or a full address on your site; links to other sites don't count). **Suggest links** (uses Ghostwriter: two calls, in the background) reads the page as the form has it and looks for a few of your published pages it could link to, by the same rules as a first draft: about one link per 250 words, two to five, on descriptive words, never in a heading, spread through the page. The button says "Finding pages to link to…" while it works. Each link it finds becomes a step of its own, **Link “tell us about your garden” to Contact us?**, with **Link it** (the words become a link in the editor; Undo takes it back, and nothing is saved until you save) or **Skip**. If no page is close enough, the step says so and you can add one by hand. **Add a link** takes you to the text; **Skip** hides it. [Suggest edits](suggest-edits.md) can propose links too.

These steps, and the rest of the SEO wording, are in German, French, Dutch and Spanish as well as English.

### Keyboard and screen readers

- **Alt+Shift+N** and **Alt+Shift+P**: the next and previous gap. **Alt+Shift+G**: open or tuck away the guide. They work anywhere except while typing in a field.
- The guide is a labelled region that never traps focus, so the form stays usable. Each step, each fix and opening or closing it are announced. Highlighted fields carry a hidden "Ghostwriter: needs a link" note, and every tag is a button.
- With **Reduce motion** on in your system, nothing moves: no flight, no bobbing, no animations when it opens or closes.

On a phone the guide is a sheet along the bottom of the screen that folds down to one line ("2 of 5 · Needs a link · Next"), and the mark doesn't fly. It follows Statamic's dark mode.

## Publishing

When an entry is saved as published (now, or on a future date), publishing a working copy included, and something in the table above blocks, the save is refused with a message on each field:

> Add adult ticket price before publishing.

> Choose where this link goes before publishing.

> Check "3 areas" before publishing.

![A count to check refused on publishing, by its field](images/finish-check-publish.png)

Saving it unpublished always works, and drafts Ghostwriter writes start unpublished (see [Use this draft](writing.md#use-this-draft)). Markers are never removed silently: a sentence with its fact cut out reads worse than one that plainly needs it.

Set **When a page with something unfinished is published** (Ghostwriter → Settings → Finish this page) to **Warn**, or `GHOSTWRITER_ON_UNFINISHED_PUBLISH=warn`, to publish with one warning naming everything instead. Visible `[[ask: …]]` text then goes live, so Block is the default.

This is the same rule as for [stock photos](stock-photos.md#publishing): one check, one message.

## Fixes that write

Two fixes ask Ghostwriter to write, and say so on the button. Each is one small request, only when you press it, and the result goes into the form for you to check and save:

- **Write around it**, on a fact to add: the sentence rewritten without the fact, adding nothing new. Use it when the page reads well enough without the detail.
- **Write it for me**, on an empty summary or other short text field: a line drawn from the page's own words.

Ghostwriter never fills in a fact. There is no "suggest a price" button, and an answer holding a figure the page doesn't have is thrown away.

## Settings

| Setting | Default | |
| --- | --- | --- |
| `publish.on_unfinished` (`GHOSTWRITER_ON_UNFINISHED_PUBLISH`) | `null` (the settings screen; **block** if that is blank) | `block` or `warn`. Replaces `stock.on_publish`, which is still read when this isn't set. |
| `finish.open_after_draft` (`GHOSTWRITER_FINISH_OPEN_AFTER_DRAFT`) | `true` | The guide opens by itself when a draft is put into the form. |
