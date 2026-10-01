# Changelog

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
