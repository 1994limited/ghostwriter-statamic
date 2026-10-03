# Finish this page

Some things only a person can finish: the price of a ticket, where a button should go, the photo for the hero. Ghostwriter never makes these up. It marks the place instead, and **Finish this page** finds every mark in an entry, highlights it on the entry's form and walks you through it. A page can't be published while something still needs you.

It works on any entry in a collection Ghostwriter writes for, whoever wrote it.

## What it finds

| What | How it looks in the entry | Blocks publishing |
| --- | --- | --- |
| **A fact to add** | `[[ask: adult ticket price]]` in the text. Ghostwriter writes this wherever a draft needs a figure, a date, a name or a quote it wasn't given. | Yes |
| **A fact for a number or date field** | The field is empty, and the draft asked for it. | When the field is required |
| **A link to choose** | A link whose address is `#gw-link:contact-page`, in Bard or Markdown, or a Link field holding `#gw-link:…`. The words stay; only where it goes is missing. | Yes |
| **A link to a page that's gone** | A Link field, an Entries field or a Bard link pointing at an entry that has been deleted. | Yes |
| **An image placeholder** | Ghostwriter's striped placeholder (`ghostwriter/image-placeholder.png` in the field's container), in an assets field or inline in Bard. See [Images](images.md#placeholders). | Yes |
| **A stock preview not licensed** | A paid library's preview. See [Stock photos](stock-photos.md). | Yes |
| **Template text** | A placeholder such as `[[item]]` left in the text. | Yes |
| **A required field left empty** | | Statamic's own validation does |
| **An image or link field left empty** that most entries like this fill | | No: it's counted, not enforced |
| **A field most entries fill, left empty**, and text such as `TBC`, `[insert date]` or `lorem ipsum` | | No: a suggestion only |

Everything is found from the entry's values and its blueprint, as you type. Finding them never asks a model and never saves anything.

### The markers

- `[[ask: …]]` is plain text, so it survives every field: Bard, Markdown, text and textarea. It shows on the page in Live Preview, on purpose. Type the fact over it, or use the guide.
- `#gw-link:` is a real link to a place on the same page, so if one were ever published it would go nowhere rather than to a wrong page. Choose an entry for it, or remove the link and keep the words.
- Neither keyword is translated. The words inside are in your site's language.

## Publishing

When an entry is saved as published (now, or on a future date), publishing a working copy included, and something in the table above blocks, the save is refused with a message on each field:

> Add adult ticket price before publishing.

> Choose where this link goes before publishing.

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
