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
- **Dark mode.** The "Ghostwriter needs your answer" card was a light amber with near-white text, and a few hover borders didn't change. Statamic's own utilities outranked the addon's dark and hover styles; they now win.

**Photo search** now comes from Ghostwriter Core 0.2.0 (`1994/ghostwriter-core ~0.2.0`), the same in all three addons (decisions D2 and D4):

- Photos are judged even where no other entry has an image in that place: the model checks each one against the block's and page's words and what the library says it shows, and leaves out clear misses. Before, those photos weren't compared at all.
- The images under a draft are found the same way as with the field button: the words of the block the image goes in, the rest of the draft, and the images already there.
- When nothing fits, a second round of searches runs; if that finds nothing either, the top results are shown with a line saying none fitted.
- **Best match** only marks photos a model judged. "Searched for: …" is shown above the results under a draft too.
- A chosen photo is named and titled from the library's own description or tags ("brown-rocks-at-golden-hour-x7k2qa.jpg"), not the search, and its description is saved as the asset's alt text where the container's blueprint has an `alt` field.
- With the search box under a draft left empty, the searches are chosen by the `photo-researcher` prompt from the draft, as on the field button. The `photo-query` prompt is no longer used.
- Photo libraries are fetched through Ghostwriter Core's HTTP client: redirects are followed only over https, never to a private address, and the key is dropped when a redirect leaves the library's host.

**Editing the draft and carrying on** (decisions C1 and C3):

- Writing in the draft is always editable in place, as in the Craft addon: click it or move to it with Tab, type, and it is saved when you leave it. Escape puts back what was there; Enter finishes a one-line field. Rich text stays rich. Before, a click turned a line into a text box first, and nothing could be reached from the keyboard.
- On a create screen, **Write with Ghostwriter** carries on with the piece last used or open there, after **Use this draft** or closing the panel, rather than going back to **What are you writing?**. The address names the piece (`?ghostwriter=…`), so reloading the page carries on too. **Start over** goes back to a new piece.

**Missing pieces** (from the UX parity audit, so the three addons match):

- **Try again** on a failed turn in the writing panel sends the same message again. Failures everywhere read "That didn’t work".
- When work has waited 30 seconds for a queue worker, the writing panel, the voice and image style screens and the image button say so, with the command to start one. Work is marked as it is queued and unmarked when a worker starts it, in `storage/ghostwriter/queued`.
- **Suggest kinds everywhere** on the dashboard looks over every collection at once.
- The settings screen lists the API keys Ghostwriter can use and whether each is set (never the keys), and has a **Search Openverse** switch. `images.openverse` in the config is now blank by default so the screen decides; **if you published the config**, change its line to `env('GHOSTWRITER_OPENVERSE')` to use the switch.
- A reopened piece whose draft is already in the form says so: using it again replaces what is there.
- Notes under the voice guide and image style editors ("Markdown. Every writing prompt includes this guide as it stands." and the `##` headings the image guide needs).
- The dashboard says "Nothing being written right now." with nothing in progress, the teach-a-kind box explains itself, and ⌘S saves a kind.
- Photo credits link to the photo on the library's site; thumbnails that won't load are left out; making a picture with the field button says it is under way.

**Shared conversations** (decision E7):

- Conversations are shared with everyone who has the Ghostwriter permission, from the dashboard, the widget, the content plan, the panel and the entry. Before, each was its starter's alone (super users aside).
- Each message says who sent it; pieces say who started them and who last changed them.
- One run at a time: while someone's request runs, others see "Ada is waiting on Ghostwriter", and sending, editing the draft or its YAML waits until it has answered.
- `shared_conversations` in the config (`GHOSTWRITER_SHARED_CONVERSATIONS`, on by default). Set it to `false` for private conversations as before.

**Fixes** (from the UX parity audit of the three addons):

- **Editing keeps what you typed.** "Edit with Ghostwriter" now starts from what the entry's form holds, unsaved typing included, and "Use these changes" puts the new writing over the form as it stands. Before, both worked from the entry as last saved, so an image or setting changed in the form and not yet saved was put back.
- "Learn this" on a suggested kind now finishes on the dashboard: the "Learning…" line stops, the new kind appears, and a failure is shown with its reason. Before, the line ran until the page was reloaded and a failure never showed.
- The dashboard's settings button, and Get started's "Open the settings", only show to people who may change the settings.
- Counts read properly in the singular: "1 idea waiting", "1 entry", "1 suggested kind to review", "1 word", and so on, on the dashboard, Get started, the widget, the content plan and the writing panel.
- Deleting a kind of content says so ("Kind deleted"), and says why when it can't. The confirmation now says "kind of content" rather than "content type".
- Choosing none of Ghostwriter's plan suggestions says "Dismissed 3 suggestions." rather than "0 added to the plan."
- An image chosen with the field button for a multi-image field that already holds as many as it allows no longer goes in over the limit. It is kept in the container, and Ghostwriter says so.

**Changes** (agreed after the UX parity audit, so the three addons work alike):

- **Settings in config win.** A value set in `config/ghostwriter.php` or `.env` (provider, model, collections, voice collections, suggesting kinds, image provider and model, placeholders) now wins over the settings screen, which shows that field locked with a note saying what it is set to. Before, a value saved on the screen silently beat the config, so saving the form once fixed the provider whatever `GHOSTWRITER_PROVIDER` said. **If you published the config before**, its `provider` line reads `env('GHOSTWRITER_PROVIDER', 'anthropic')`, which now fixes the provider to Claude: change it to `env('GHOSTWRITER_PROVIDER')`, and likewise drop the defaults from `suggest_kinds` and `images.placeholders`, to choose them on the screen again.
- The time limit for each model call is 300 seconds by default (was 180), and is set in the config only.
- Saving a model name that looks like another provider's ("gpt-…" with Claude chosen) shows a warning. It is still saved.
- **Get started** counts only the five required steps, so the bar reaches the end, and opens on the first required step still to do. Once they're done, the dashboard keeps a one-line "You're set up" until Get started is hidden. Hiding and showing it is for people who may change the settings, as it applies to everyone.
- Get started's second step lets people who may change the settings choose the collections to write for and learn the voice from right there.
- The dashboard widget says when there is no API key yet, with a link to add one; "Write something" stays visible but disabled.
- Guide buttons say what they do: "Write the voice guide" and "Describe the images" the first time, then "Rescan and rewrite" and "Look again and rewrite". Rescanning waits while there are unsaved edits, as asking for a change already did. A failed image style run stays explained until the next run, as the voice guide's does.
- "Teach a kind" (was "Teach it a kind"). After **Learn this** in the teach box, it closes and the collection's row shows the kind being learned, then the new kind or why it failed.
- Suggested kinds on Get started show example entries, as on the dashboard, and "Learn all" there asks first.
- The kind editor no longer shows each question's handle (it is made from the wording and kept when you reword it), and a kind needs at least one question.
- After a draft is used, the notes about what is left to do come as one notice listing them all, which stays until it is closed, rather than a toast each.
- Ghostwriter's replies in the conversation are shown formatted (lists, bold), with any HTML escaped.
- When Ghostwriter has asked something, the reply box says "Your turn: …" (was "Waiting on you").
- The image dialog is a little wider (about 48rem) with photos in three columns at the same shape, each with a **Use this** button as well as being clickable. "Best match" is only shown on photos the model compared with the site's own; when there was nothing to compare with, a line says so. The draft panel's photos get **Use this** too.
- The placeholder setting is called "Mark images still to choose" (was "Striped placeholders").
- **Content plan:** closing the "Ghostwriter suggests" box no longer throws the suggestions away. They wait, with an "N suggestions waiting" card at the top of the plan whose **Review** opens them again; only **Drop them all** discards them. Every waiting suggestion starts ticked when the plan is opened. Open ideas are listed newest first within each collection. **Put back** is offered only on dismissed ideas, not finished ones.
- A piece counts as finished only once its entry is saved. Changes put into an existing entry's form stay in progress ("Changes in the form, not saved") until the entry is saved, and an older entry that happens to share a new piece's title no longer marks it finished.
**Removed:**

- **Logo cards.** The image dialog's "Logo card" tab is gone, along with its routes (the field button's and the writing panel's) and the Imagick drawing code. The image button now offers **Find a photo** and **Make one**. For a logo, add the file to the field yourself, or give it to **Make one** as your own image. Logo cards already saved stay in their containers. Imagick is no longer needed for anything but sending smaller copies of images to the model.

- For developers: `NineteenNinetyFour\Ghostwriter\Drafts\Draft` is now `NineteenNinetyFour\Ghostwriter\Core\Text\Draft`. The old name still works, so a custom `EntryWriter` keeps working; it goes in 2.0. The `Ai\Agents` classes are gone.

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
