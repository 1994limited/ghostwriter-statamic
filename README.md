# Ghostwriter

A Statamic 6 addon that learns how a site writes, then drafts new entries in that voice through a short questionnaire and a follow-up conversation. It works on any collection because it takes its instructions from the collection's blueprint and the entries already in it.

It uses the [Laravel AI SDK](https://laravel.com/docs/ai-sdk), so it runs on Claude, ChatGPT or any other provider that package supports, with your own API key.

## How it works

**1. The voice guide.** Ghostwriter reads a sample of your published entries and writes a tone of voice guide: who is talking and to whom, how pieces are shaped, the words you use and the ones you never do, with quoted examples. The guide is a markdown file in your project (`resources/ghostwriter/voice.md`). Edit it in the Control Panel's markdown editor, or ask for a change in plain words.

**2. Reading the collection.** Ghostwriter reads the collection's blueprint and its existing entries and works out how pages there are really built: which fields are written, which page-builder blocks are used and in what order, and which settings never change. This needs no setup and no model call, so every collection you switch on can be written for straight away.

**3. Writing.** On a collection's create screen, **Write with Ghostwriter** opens a panel beside the form. What it offers depends on the site it is installed on:

- **Kinds it found.** Entries that are built the same way are grouped, so a Pages collection might offer "Like the pages under Services" beside "Like About Us and Our Values". Choosing one models the new entry on those.
- **Something else.** A general brief for anything. Tick the entries to model it on (a structured collection is listed as its tree), or tick none and describe the shape you want.
- **Kinds you taught it.** For content you write often, *Teach it a kind* has Ghostwriter write a brief for that kind alone, with its own questions and guidance.

You answer the brief; Ghostwriter asks for anything it still needs, then drafts. You ask for changes in conversation. **Use this draft** fills in the form underneath, which you check and save as usual. Nothing is published for you.

**4. Images.** Under the draft, each image field the entry normally has gets two options. *Make image* has an image model draw one, given the pictures the same field holds on the entries the draft is modelled on as the style to match; you can add an image of your own, such as a logo, for it to be built around. This needs `OPENAI_API_KEY` or `GEMINI_API_KEY` (Claude does not make images). *Find a photo* searches free libraries: Openverse with no key (public-domain and CC0 work only), plus Unsplash, Pixabay and Pexels when `UNSPLASH_ACCESS_KEY`, `PIXABAY_API_KEY` or `PEXELS_API_KEY` is set. The chosen image is saved to the field's asset container, with its credit, and goes into the form with the draft. Nothing is made or downloaded until you ask.

The writer is told to use only facts from the brief and the conversation. It does not invent figures, quotes or client names.

## Requirements

- Statamic 6
- PHP 8.3+
- An API key for a provider the Laravel AI SDK supports

## Installation

```bash
composer require 1994/ghostwriter-statamic
```

Add your key to `.env`:

```
ANTHROPIC_API_KEY=your-key
```

or `OPENAI_API_KEY` for ChatGPT. The key is read by the Laravel AI SDK and is never stored by Ghostwriter.

Then open **Tools → Ghostwriter** in the Control Panel.

## Setting up

1. **Settings** (under Ghostwriter in the navigation): choose the collections to write for, the collections to learn the voice from, and the provider and model.
2. **Voice guide**: generate it, read it, correct it.
3. Write. Optionally, for a kind of content you write often, *Teach it a kind*: leave the entries unticked to learn from the newest published entries, or tick up to six to model it on, then review the questions and guidance it wrote.

Users need the **Write content and edit the voice guide with Ghostwriter** permission.

## Where things are kept

| What | Where | Versioned |
| --- | --- | --- |
| Voice guide | `resources/ghostwriter/voice.md` | Yes |
| Content types | `resources/ghostwriter/types/*.yaml` | Yes |
| Settings | `resources/addons/ghostwriter-statamic.yaml` | Yes |
| Sessions (brief, conversation, draft) | `storage/ghostwriter/` | No |

No database is needed.

## How blueprints are read

Every field is reduced to a kind:

| Kind | Fieldtypes | The writer produces |
| --- | --- | --- |
| text, long text | text, textarea | plain strings |
| rich text | bard, markdown | markdown, converted to a Bard document where the field is Bard |
| choice | select, radio, button group, checkboxes | one of the field's options |
| toggle, number, list | toggle, integer, float, list, taggable | the value |
| blocks | replicator | a list of sets, each with its own fields |
| rows, group | grid, group | nested fields |
| reference | assets, entries, users, terms, link, date and others | nothing; left for a person |

From existing entries it also learns, per replicator field, the usual order of sets, how often each is used, which fields are ever filled in, and which values are the same on nearly every entry. Those house defaults are applied when the entry is built, so the writer only deals with the writing. Where a Bard field offers a quote-style set, block quotes are stored as that set.

## Content type files

A type is YAML and can be edited by hand or on its screen in the Control Panel:

```yaml
title: Case study
description: Tells one project from start to finish.
collection: case_studies
blueprint: case_study          # optional, for collections with several
examples: [entry-id, ...]      # optional: model the type on these entries
where: { categories: guides }  # optional: learn only from entries matching this
defaults: { categories: [guides] }   # optional: entry data set on everything written
questions:
  - handle: client
    label: Who is the client?
    type: text                 # text or textarea
    required: true
guidance: |
  Markdown: who the reader is, what each part does and in what order, how long it runs.
checklist:
  - The result states only outcomes given in the brief.
```

## Configuration

`config/ghostwriter.php` sets code-level defaults; the settings screen takes precedence. Publish it with:

```bash
php artisan vendor:publish --tag=ghostwriter-config
```

The prompts are plain markdown files and can be overridden per project:

```bash
php artisan vendor:publish --tag=ghostwriter-prompts
```

Model calls can take a minute or more. With a real queue connection they run as queued jobs. On the `sync` driver they run after the HTTP response has been sent, in the same PHP process, while the screen polls for the result. This needs PHP-FPM (Herd, Forge and most hosts); the single-threaded `php artisan serve` will block.

To save a draft straight to an entry from your own code, resolve `Contracts\EntryWriter`. Bind your own implementation to take over how entries are built.

## Development

```bash
composer install
npm install
npm run build        # builds resources/dist, which is committed
# then, in a site using it: php artisan vendor:publish --tag=ghostwriter-statamic --force
vendor/bin/phpunit
```

Tests fake every model call, so they need no API key.
