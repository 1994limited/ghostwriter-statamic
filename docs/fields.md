# How Ghostwriter reads your fields

This page covers how Ghostwriter reads a collection's blueprint and entries, and the house style it copies into new entries. It works with any collection because it reads these, rather than assuming a shape.

## Fieldtypes

Each field is reduced to a kind. The writer fills in the writing; everything else is left for a person or filled from the house style.

| Kind | Fieldtypes | The writer produces |
| --- | --- | --- |
| text, long text | text, textarea, color | plain text |
| rich text | bard, markdown | markdown, converted to a Bard document where the field is Bard |
| choice, choices | select, radio, button group; checkboxes, multi-select; an assets field limited to one small folder (such as a set of logos) | one or more of the field's options |
| toggle, number, list | toggle; integer, float, range; list, taggable | the value |
| blocks | replicator | a list of sets, each with its own fields |
| rows, group | grid, group | nested fields |
| reference | assets, entries, users, terms, link, date and others | nothing; left for a person, a placeholder or the house style |

Section, HTML, spacer and hidden fields hold nothing and are skipped. The top-level slug is derived from the title.

## Learning from your entries

Without any setup, Ghostwriter reads a collection's entries and learns:

- which page-builder sets are used, how often, and in what order
- which fields are ever filled in
- which settings are the same on nearly every entry (these become house defaults)
- which sets are the same on every entry, such as process steps or testimonials, even when they come in a few pasted versions; these are copied, not written, and the writer only places them

A kind of content uses its own example entries for this; otherwise the newest published entries are used. How often each image field is filled is learned too, which is what the placeholders go by.

## House style

When a draft goes into a **new** entry, Ghostwriter also copies what the example entries agree on, **place by place**:

- **Settings by position.** If the first spacer on every page is 45/65 and the last is 60/100, the new page gets the same. A setting is copied when more than half the examples agree.
- **Nested items.** If every page has three breadcrumbs, the new page gets three.
- **Links.** A link is copied when nearly every example has the same one (at least 80%), or when more than half do and none has anything different.
- **Rich text dressing.** If a hero heading is always centred and bold, the writer's plain heading gets the same Bard attributes and marks. The writer only writes words.

### Links to the page itself

A link from a page to itself, such as the last breadcrumb, is recognised as one. If two or more examples link to themselves in the same place, and none links anywhere else there, the new entry links to itself, under its own title, once it has been saved and has an ID.

### Links it can't decide

If a block should have a link (the field is required, or that kind of block usually has one), but the examples don't agree on where it goes, Ghostwriter points it at `https://example.com` with the text "Link to choose" in a matching text field. The page still works, and the gap is easy to spot. The notes above the form, after **Use this draft**, list each one as "(links to example.com for now)". Set them before publishing.

This works for the `link` fieldtype. Entries fields can't take a web address, so they're simply listed as still to set.

House style is never applied when [editing](editing.md) an existing entry, which keeps its own.

Next: [Privacy and data](privacy.md).
