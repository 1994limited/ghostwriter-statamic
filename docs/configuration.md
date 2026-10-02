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
| **Model** | Leave blank for the provider's default. Saving a model name that belongs to another provider ("gpt-…" with Claude chosen, say) shows a warning; it is still saved. |
| **Image provider** | OpenAI or Gemini, for making images. Blank uses whichever has a key. |
| **Image model** | Leave blank for the provider's default. |
| **Mark images still to choose** | A striped placeholder in empty image fields on new entries, where entries usually have an image or the field is required. |

Settings are kept in `resources/addons/ghostwriter-statamic.yaml`. API keys are never set here. A setting fixed in `config/ghostwriter.php` or `.env` shows locked, with a note saying what it is set to; see below. The time limit for each model call is set in the config only.

## config/ghostwriter.php

To set any of these in code, or differently per environment, publish the config:

```bash
php artisan vendor:publish --tag=ghostwriter-config
```

A value set in the config file (or its environment variable) wins over the settings screen, which then shows that field locked, with a note saying where it is set. Leave a value `null` (or a list empty) to let the settings screen decide. The settings that work this way are `provider`, `model`, `collections`, `voice.collections`, `suggest_kinds`, `images.provider`, `images.model` and `images.placeholders`.

If you published the config before 1.1.0, its `provider` line reads `env('GHOSTWRITER_PROVIDER', 'anthropic')`, which now fixes the provider to Claude. Change it to `env('GHOSTWRITER_PROVIDER')` to choose the provider on the settings screen again; do the same for `suggest_kinds` and `images.placeholders`.

| Key | Default | |
| --- | --- | --- |
| `provider` | `null` (the settings screen; Claude if that is blank) | `anthropic`, `openai` or `gemini` |
| `model` | provider's default | |
| `timeout` | `300` | Seconds to wait for one response. Queued jobs are allowed three times this plus 60, as busy providers are retried. Set here only, not on the settings screen |
| `keys.anthropic`, `keys.openai`, `keys.gemini` | from `.env` | API keys. If one is empty, the key in `config/ai.php` is used, if there is one |
| `base_urls.anthropic`, `base_urls.openai`, `base_urls.gemini` | `null` | A gateway that speaks the provider's own API, instead of the provider's address. Must be `https://`, except on localhost |
| `anthropic_fallbacks` | `true` | Pass a request Claude declines to the model Anthropic recommends, on models that support it |
| `log_channel` | `null` (default channel) | Where each call is logged: provider, model, tokens and time, never the words |
| `collections` | `[]` (all) | Collection handles to write for |
| `voice.collections` | `[]` (all) | Collection handles read for the voice guide |
| `voice.max_entries`, `voice.max_chars_per_entry`, `voice.max_chars` | `24`, `6000`, `90000` | How much is read for the voice guide |
| `suggest_kinds` | `null` (the settings screen; on if that is blank) | Look for kinds without being asked |
| `images.provider` | `null` | `openai` or `gemini`; `null` uses whichever has a key |
| `images.model` | provider's default | |
| `images.openverse` | `null` (the settings screen; on if that is blank) | Search Openverse |
| `images.placeholders` | `null` (the settings screen; on if that is blank) | Mark images still to choose with a striped placeholder in empty image fields |
| `images.guide_samples` | `10` | Images looked at per collection for the image style guide |
| `images.unsplash_key`, `images.pixabay_key`, `images.pexels_key` | from `.env` | Photo library keys |
| `plan.suggestions` | `8` | Ideas asked for each time the plan looks for gaps |
| `types_path` | `resources/ghostwriter/types` | Kinds of content |
| `plan.path` | `resources/ghostwriter/ideas.yaml` | The content plan |
| `images.guide_path` | `resources/ghostwriter/imagery.md` | The image style guide |
| `voice.path` | `resources/ghostwriter/voice.md` | The voice guide |
| `shared_conversations` | `true` | Everyone with Ghostwriter access sees and can carry on with every conversation; `false` keeps each to its starter (super users aside) |
| `sessions_path` | `storage/ghostwriter/sessions` | Working state (the rest of `storage/ghostwriter/` sits beside it) |
| `writer` | `SchemaEntryWriter::class` | The class that turns a draft into an entry; bind your own to take over |

Environment variables for the common ones: `GHOSTWRITER_PROVIDER`, `GHOSTWRITER_MODEL`, `GHOSTWRITER_TIMEOUT`, `GHOSTWRITER_SUGGEST_KINDS`, `GHOSTWRITER_IMAGE_PROVIDER`, `GHOSTWRITER_IMAGE_MODEL`, `GHOSTWRITER_OPENVERSE`, `GHOSTWRITER_PLACEHOLDER_IMAGES`, `GHOSTWRITER_ANTHROPIC_BASE_URL`, `GHOSTWRITER_OPENAI_BASE_URL`, `GHOSTWRITER_GEMINI_BASE_URL`, `GHOSTWRITER_ANTHROPIC_FALLBACKS`, `GHOSTWRITER_LOG_CHANNEL`.

`config/ai.php` from the Laravel AI SDK no longer applies, apart from the keys fallback above.

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

Every prompt Ghostwriter uses is a markdown file, shipped with Ghostwriter Core (`vendor/1994/ghostwriter-core/resources/prompts/`):

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
| `photo-picker.md` | Choosing the photographs that suit the site |
| `image.md` | Making an image |

Publish them, then edit any one; Ghostwriter uses your copy from then on. Keep any `{{ placeholders }}` that are in the original. `[[placeholders]]`, such as `[[place]]`, are filled with Statamic's own words (website, section, entry); keep them or write the words in.

```bash
php artisan vendor:publish --tag=ghostwriter-prompts
```

## Permissions

One permission, **Write content and edit the voice guide with Ghostwriter**. Editing an entry through Ghostwriter also needs Statamic's own permission to edit that entry. See [Installation](installation.md#permissions).
