**Ghostwriter writes new entries in your site's voice and builds them into your own page layouts: the right sets, in the right order, with your house style. It edits existing entries the same way, right beside the entry form.**

Tell it what you're writing in a line or two. It fills in the brief, asks for anything only you know, writes the draft and puts it into the entry for you to check and save. Nothing is ever published for you.

It runs on Claude, ChatGPT or Gemini with your own API key, or connect an OpenRouter account in one click instead. It works with whatever your blueprints already use: text fields, Bard or Markdown, Grid and Group, and Replicator page builders.

## Drafts that fit the page

Ghostwriter reads each collection's blueprint and the entries already in it, and learns how pages there are really built:

- Which Replicator sets are used, in what order, and which settings never change
- **House style**, place by place: settings by position, nested items such as breadcrumbs, how headings are dressed in Bard
- A link from a page to itself becomes a link to the new page. A link it can't decide is clearly marked for you to set.
- Image fields a page usually fills get a striped placeholder, so you can see where pictures go

The writer only writes words. Everything else comes from your own pages, so a draft looks like it belongs.

## The brief, in the conversation

Choose what you're writing, and Ghostwriter asks: "What's it called, and what should it say?" From your reply it fills in the whole brief for that kind of content, as a card you can edit: every question answered, and the entries to model it on ticked. Anything it can't know stays in [square brackets] for you. **Looks right, start writing**, or **Try again**.

## Your voice, written down

Ghostwriter reads a sample of your published entries and writes a **voice guide**: who is talking and to whom, how pieces are shaped, the words you use and the ones you never do, with real examples from your site. Edit it by hand, or ask for changes in plain words: "We never say solutions."

## Kinds of content

Ghostwriter suggests the kinds of content each collection holds, such as "Project story" or "Service page". Learn one, and it gets its own questions and guidance, modelled on the entries you pick.

## Edit what's already there

**Edit with Ghostwriter** on an existing entry opens the same conversation, with the entry as the draft. Ask for changes, and they go into the form. Only the writing changes: images, links, chosen entries and settings stay as they were, and the entry is untouched until you save.

## Pictures that look like yours

A **Find a photo** button sits on every assets field. It reads the set the field is in, and the page around it, then:

- **Finds a photo** from free libraries (Openverse with no key; Unsplash, Pexels and Pixabay with yours). The model ranks the results against the page's words and the images you already use there, and marks the best matches.
- **Makes one** in your site's style, with an OpenAI or Gemini key

The image you choose is saved as an asset in the field's own container and dropped straight into the field. A found photo gets a name, title and alt text from the library's own description of it, and its credit is kept on the asset.

An **image style guide** describes what your pictures look like, collection by collection, so every search and every new image is made to match.

## Stock photos, licensed properly

Search **Shutterstock (API plan required)** from the same dialog as the free libraries. Getty Images and iStock are coming. A demo library shows the whole flow on a test site, without an account or any charge.

- **See it on the page first.** Insert a preview where the photo will go. Only signed-in editors see the library's watermarked preview, in the Control Panel and in Live Preview; everyone else sees a striped stand-in.
- **License & replace** buys the photo once, through your own account, and swaps the full image into the same asset. Every entry using it shows the licensed photo, and its alt text stays as it was.
- **No preview goes live.** An entry can't be published while it holds one. Prefer a warning? Choose that in the settings.
- **Every licence on record.** The **Stock images** screen lists every stock photo on the site: where it's used, who licensed it and when, and the credit line to show. Download a licence record, or export the lot as CSV.

Licensing has its own permission, so only the people you choose can buy photos. Anyone else can send a **Request licence**.

## Finish this page

Ghostwriter never makes up a price, a date or a name. Where a draft needs a fact it doesn't have, it marks the place, and a link it can't settle points nowhere until you choose.

Every gap is highlighted in the entry form, with a count beside Save, and a guide with the Ghostwriter mark walks you through them one by one: type in the fact, link to the right page, swap the placeholder image, license the preview. **The page can't be published while one of them remains.** Prefer a warning? Choose that in the settings.

## Know what to write next

The **content plan** reads what each collection has and what it lacks, and suggests what's missing. **Draft this** opens a new entry with the brief already filled in from the idea.

## Up and running in minutes

**Get started** walks you through setup step by step. Connect a model, choose your collections, learn your voice, teach it your kinds of content, and write. The **Overview** and a widget for Statamic's dashboard keep what's in progress in view.

## Built to be trusted

- **Your keys stay on your site.** Ghostwriter sends them only to the provider you chose, never to us. A gateway or proxy can be set per provider.
- **No invented facts.** The writer uses only what's in the brief and the conversation: no made-up figures, quotes or client names.
- **Nothing published for you.** Drafts go into the entry form, for a person to check and save, and new entries start unpublished (a setting, on by default).
- **Nothing sent until you ask.** Content goes to your chosen provider only when someone in the Control Panel starts something.
- **Kept in your project.** Guides, kinds, the content plan and stock records are files in your project, and every prompt can be overridden there.
- **Written together.** With Statamic Pro, conversations are shared with everyone who can use Ghostwriter, so a colleague can pick up a piece where you left it. Each message shows who sent it.

## Requirements

- Statamic 6.30 or later
- **Statamic Pro** for roles, permissions and shared conversations. On Statamic Core, the one super user has full access.
- PHP 8.3 or later, with GD
- A queue worker, or PHP-FPM on the `sync` queue
- An API key for Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini), or an OpenRouter account. Usage is billed to your own account by that provider.
- Optional: an OpenAI or Gemini key to make images
- Optional: a Shutterstock API plan, to license stock photos
