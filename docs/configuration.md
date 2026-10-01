# Configuration

## Settings

**Ghostwriter → Settings** (for whoever may edit the addon's settings):

| Setting | What it does |
| --- | --- |
| **Write for these collections** | Collections that get **Write with Ghostwriter**. None means all. |
| **Learn the voice from these collections** | Collections read for the voice guide. None means all. |
| **Show Get started** | Brings the setup steps back after they were hidden. Acts when saved; not stored. |
| **Suggest kinds of content** | Look at each collection for kinds without being asked. |
| **Provider** | Anthropic, OpenAI or Gemini, for writing. |
| **Model** | Leave blank for the provider's default. |
| **Image provider** | OpenAI, Gemini or xAI, for making images. Blank uses whichever has a key. |
| **Image model** | Leave blank for the provider's default. |
| **Striped placeholders** | Placeholders in empty image fields on new entries. |

Settings are kept in `resources/addons/ghostwriter-statamic.yaml`. API keys are never set here.

## config/ghostwriter.php

To set any of these in code, or differently per environment, publish the config:

```bash
php artisan vendor:publish --tag=ghostwriter-config
```

A value saved on the settings screen takes precedence over the config file.

| Key | Default | |
| --- | --- | --- |
| `provider` | `anthropic` | Any provider in `config/ai.php` |
| `model` | provider's default | |
| `timeout` | `180` | Seconds to wait for one response |
| `collections` | `[]` (all) | Collection handles to write for |
| `voice.collections` | `[]` (all) | Collection handles read for the voice guide |
| `voice.max_entries`, `voice.max_chars_per_entry`, `voice.max_chars` | `24`, `6000`, `90000` | How much is read for the voice guide |
| `suggest_kinds` | `true` | Look for kinds without being asked |
| `images.provider` | `null` | `openai`, `gemini` or `xai`; `null` uses whichever has a key |
| `images.model` | provider's default | |
| `images.openverse` | `true` | Search Openverse |
| `images.placeholders` | `true` | Striped placeholders in empty image fields |
| `images.guide_samples` | `10` | Images looked at per collection for the image style guide |
| `images.unsplash_key`, `images.pixabay_key`, `images.pexels_key` | from `.env` | Photo library keys |
| `plan.suggestions` | `8` | Ideas asked for each time the plan looks for gaps |
| `types_path` | `resources/ghostwriter/types` | Kinds of content |
| `plan.path` | `resources/ghostwriter/ideas.yaml` | The content plan |
| `images.guide_path` | `resources/ghostwriter/imagery.md` | The image style guide |
| `voice.path` | `resources/ghostwriter/voice.md` | The voice guide |
| `sessions_path` | `storage/ghostwriter/sessions` | Working state (the rest of `storage/ghostwriter/` sits beside it) |
| `writer` | `SchemaEntryWriter::class` | The class that turns a draft into an entry; bind your own to take over |

Environment variables for the common ones: `GHOSTWRITER_PROVIDER`, `GHOSTWRITER_MODEL`, `GHOSTWRITER_TIMEOUT`, `GHOSTWRITER_SUGGEST_KINDS`, `GHOSTWRITER_IMAGE_PROVIDER`, `GHOSTWRITER_IMAGE_MODEL`, `GHOSTWRITER_OPENVERSE`, `GHOSTWRITER_PLACEHOLDER_IMAGES`.

## Where things are kept

| What | Where | Commit it? |
| --- | --- | --- |
| Voice guide | `resources/ghostwriter/voice.md` | Yes |
| Image style guide | `resources/ghostwriter/imagery.md` | Yes |
| Kinds of content | `resources/ghostwriter/types/*.yaml` | Yes |
| Content plan | `resources/ghostwriter/ideas.yaml` | Yes |
| Prompt overrides | `resources/ghostwriter/prompts/*.md` | Yes |
| Settings | `resources/addons/ghostwriter-statamic.yaml` | Yes |
| Conversations, drafts, suggestions, job status, images waiting to be used | `storage/ghostwriter/` | No |
| Published Control Panel assets | `public/vendor/ghostwriter-statamic/` | No |

Ghostwriter adds no database tables. The guides, kinds and plan are project files, so they move between environments with your code. If editors change them on production, pull the changes back into your repository.

## Overriding prompts

Every prompt Ghostwriter uses is a markdown file in the addon's `resources/prompts/`:

| Prompt | Used for |
| --- | --- |
| `writer.md` | Writing and revising drafts |
| `brief-writer.md` | Filling in a brief from a title and notes |
| `voice-analyst.md`, `voice-editor.md` | Writing and changing the voice guide |
| `type-analyst.md` | Learning a kind of content |
| `kind-finder.md` | Suggesting kinds of content |
| `imagery-analyst.md` | Writing the image style guide |
| `planner.md` | Suggesting content plan ideas |
| `photo-researcher.md` | Choosing photo searches for a field |
| `image.md` | Making an image |

Publish them, then edit any one; Ghostwriter uses your copy from then on. Keep any `{{ placeholders }}` that are in the original.

```bash
php artisan vendor:publish --tag=ghostwriter-prompts
```

## Permissions

One permission, **Write content and edit the voice guide with Ghostwriter**. Editing an entry through Ghostwriter also needs Statamic's own permission to edit that entry. See [Installation](installation.md#permissions).
