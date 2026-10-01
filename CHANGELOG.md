# Changelog

## 1.1.0 — unreleased

Ghostwriter now shares its core with the Filament and Craft addons, as the package `1994/ghostwriter-core`: draft handling, the prompts and the connection to the models.

- **Providers.** Ghostwriter has its own connection to Anthropic, OpenAI and Gemini, and no longer needs the Laravel AI SDK (`laravel/ai`). Keys are read from the same variables as before (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`), now through `keys` in `config/ghostwriter.php`. Settings in `config/ai.php` no longer apply, except that a key still set there is used when the variable is empty.
- Only Anthropic, OpenAI and Gemini are supported. xAI (Grok) can no longer make images; a site that chose it uses whichever image provider has a key.
- OpenAI-compatible gateways, and gateways for the other two, can be used with `base_urls` in the config.
- Default models come from Ghostwriter Core when **Model** is blank: Claude Opus 5.5, GPT-6.1 Sol and Gemini 3.8 Flash for writing; GPT Image 2.5 Sunburst and Gemini 3.1 Flash Image for images.
- **Reliability.** A provider that is busy or limiting requests is tried again automatically, up to three times, following its `retry-after`. Queued jobs are allowed `timeout × 3 + 60` seconds to make room for it; raise your worker's own limit to match.
- **Cut-off replies.** A reply that runs out of room is asked for once more with twice the room. If a draft, a kind of content or a voice guide still doesn't fit, the turn fails with a message saying so, rather than a half-written one being saved. Ideas, kinds and photo searches keep what came back.
- **Token limits** are the same in all three addons. Most went up (a draft or a voice guide from 8,000 to 16,000). The photo search and photo picking calls went from 60 and 120 tokens to 2,000, as models that think before answering ran out of room and came back empty, so photo search is more reliable.
- **Token counts** now include Anthropic's cache-writing tokens and Gemini's thinking tokens, which are billed, so they can be higher than before for the same work.
- Each call is logged on `log_channel` with the provider, model, tokens and time, never the words.
- Get started says when another provider's key is set but not chosen.
- Prompts ship with Ghostwriter Core. `php artisan vendor:publish --tag=ghostwriter-prompts` still publishes them to `resources/ghostwriter/prompts/`, where an edited copy still takes precedence. The photo picker's prompt is now a file too, `photo-picker.md`.
- For developers: `NineteenNinetyFour\Ghostwriter\Drafts\Draft` is now `NineteenNinetyFour\Ghostwriter\Core\Text\Draft`. The old name still works, so a custom `EntryWriter` keeps working; it goes in 2.0. The `Ai\Agents` classes are gone.
- Installing: until Ghostwriter Core (`~0.1.0`) is on Packagist, add its GitHub repository to your project's `composer.json` (see the README). It is public; no token is needed.

## 1.0.1 — 2026-10-01

Fixes from the pre-release review.

- A conversation belongs to whoever started it: nobody else sees it on the dashboard, in the collection panel, the widget or the content plan, or can open, read, write in, apply or delete it. Super users see all. Conversations from before this release stay open to everyone, as they were.
- Putting a draft into a new entry, or saving it straight to one, needs Statamic's own permission to create entries in that collection; editing an existing one needs permission to edit it.
- Saving an image into a field, from a conversation or the field button, needs permission to upload to the field's asset container.
- Logo uploads are checked harder: only PNG, WebP or SVG by content; Imagick is told the format rather than left to guess; an SVG with scripts, external references, `<use>`, `<style>`, `url()` or `@import` is refused, however it is padded.
- Photograph downloads follow redirects over HTTPS only and stop reading at the size cap.
- The Composer package no longer ships docs, scripts, tests or frontend sources (22 MB down to under 1 MB); a LICENSE file; direct dependencies declared; `ext-imagick` suggested.

## 1.0.0 — 2026-10-01

First release.

- Voice guide: learned from published entries, edited in the Control Panel, refined by chat.
- Image style guide: learned from the images entries use, a section per collection.
- Kinds of content: suggested per collection and learned on request, or taught by hand; a general brief works on every collection with no setup.
- Writing: a quick brief, a questionnaire, a conversation and a draft on the collection's own create screen; "Use this draft" fills the form; click to edit any piece of writing in place; Blocks and Text views.
- Editing: "Edit with Ghostwriter" on existing entries, keeping images, links and settings.
- House style: settings, links, nested items and rich-text dressing copied from where the model entries agree; striped placeholders where an image is still to come.
- Images: a Ghostwriter button on every assets field to find a photograph (Openverse, Unsplash, Pexels, Pixabay), have one made (OpenAI, Gemini) or compose a logo card, matched to the pictures already in that place.
- Content plan: suggested entries the site is missing, reviewed before they join the plan, each opening a pre-filled brief.
- Get started steps, a dashboard, and a Control Panel dashboard widget.
