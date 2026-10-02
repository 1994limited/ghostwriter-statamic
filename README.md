<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/logo-dark.png">
    <img src="docs/logo-light.png" alt="Ghostwriter" width="360">
  </picture>
</p>

<p align="center">A Statamic 6 addon that learns how your site writes and what its pictures look like, then drafts and edits entries in that voice from a short brief and a conversation, beside the entry form.</p>

<p align="center">
  <img src="docs/images/writing-draft.png" alt="The Ghostwriter panel with a draft of a page in Blocks view, beside the conversation that wrote it" width="800">
</p>

## What it does

- **Voice guide.** Reads your published entries and writes a guide to how the site sounds, which you can edit or change by asking.
- **Kinds of content.** Suggests the kinds of entry each collection holds, such as a project story or a service page, and learns each one as a brief of its own.
- **Writing and editing in conversation.** **Write with Ghostwriter** turns a short brief into a draft; **Edit with Ghostwriter** changes an existing entry. Ask for changes, or click any writing to change it, then put it into the form to check and save. Nothing is published for you.
- **House style.** New entries copy what your existing entries agree on: block settings, links, and how rich text is dressed.
- **Photo search.** Finds free photos for any assets field, ranked by the model against the page's words and the images already in that place, with names, alt text and credits from the library.
- **Make an image.** Makes a picture in your site's own style, with an OpenAI or Gemini key.
- **Content plan.** Suggests entries the site is missing, to keep or dismiss, each ready to draft.
- **Shared conversations.** Everyone with access can carry on a piece, with each message showing who sent it.

## Requirements

- PHP 8.3 or later, with GD
- Statamic 6
- An API key for Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini)
- A queue worker, or PHP-FPM on the `sync` queue

## Installation

```bash
composer require 1994/ghostwriter-statamic
php artisan vendor:publish --tag=ghostwriter-statamic --force
```

Then add your key to `.env`, for example `ANTHROPIC_API_KEY=sk-ant-...`, and keep a queue worker running (`php artisan queue:work --timeout=960`). See [Installation](docs/installation.md) for the details.

## Quick start

1. Open **Tools → Ghostwriter → Get started** in the Control Panel.
2. Check **Connect a model** names your provider, and choose the collections to write for.
3. **Write the voice guide**, and read it over.
4. Open a collection's **Create Entry** screen and click **Write with Ghostwriter**.
5. Give it a working title and a few notes, **Start writing**, then **Use this draft** and save.

## Documentation

The full documentation is in [docs/](docs/README.md): setting up, writing and editing, the guides, kinds, images, the content plan, and reference pages.

## Providers and privacy

Ghostwriter writes with Claude, ChatGPT or Gemini, on your own account and key, and can make images with ChatGPT or Gemini and search Openverse, Unsplash, Pexels and Pixabay. It sends nothing until someone in the Control Panel asks for something, keeps everything in files in your project, and logs each call's tokens and time but never the words. See [API keys](docs/api-keys.md) and [Privacy and data](docs/privacy.md).

## Support, changelog and licence

- **Support:** [GitHub issues](https://github.com/1994limited/ghostwriter-statamic/issues), or hello@1994.co.uk.
- **Changelog:** [CHANGELOG.md](CHANGELOG.md).
- **Licence:** proprietary, © 1994 Limited. See [LICENSE](LICENSE).
- **Developing:** run `composer install` before `npm ci` (npm takes Statamic's UI package from `vendor/`), then `npm run build` (the build in `resources/dist` is committed) and `vendor/bin/phpunit`. Tests fake every model call, so they need no key.
