# Ghostwriter documentation

Ghostwriter learns how your site writes and what its pictures look like, then drafts and edits entries in that voice from a short brief and a conversation, beside the entry form in Statamic's Control Panel. It finds or makes images to match, and keeps a plan of what the site is missing.

## Setting up

1. [Installation](installation.md): requirements, installing, the queue, updating.
2. [Get started](getting-started.md): the seven setup steps in the Control Panel.
3. [API keys](api-keys.md): keys for Claude, ChatGPT or Gemini, gateways, retries, and the free photo libraries.
4. [Permissions](permissions.md): who can use and manage Ghostwriter, and shared conversations.
5. [Configuration](configuration.md): the settings screen, `config/ghostwriter.php`, where things are kept, prompts and logging.

## Using Ghostwriter

6. [Writing a new entry](writing.md): the brief, the conversation, the draft and **Use this draft**.
7. [Editing an existing entry](editing.md): changing an entry in conversation.
8. [The voice guide and image style guide](guides.md): how your site sounds, and what its pictures look like.
9. [Kinds of content](kinds.md): teaching Ghostwriter the things you write often.
10. [Images](images.md): finding and making images, ranking, alt text and placeholders.
11. [Stock photos](stock-photos.md): paid libraries, previews, License & replace, the publish rule and the Stock images screen.
12. [Finish this page](finish-this-page.md): facts to add, links to choose and images to pick, the guide, and the publish rule.
13. [The content plan](content-plan.md): ideas for what to write next.
14. [The Overview and widget](dashboard.md): what is in progress, at a glance.

## Reference

15. [How Ghostwriter reads your fields](fields.md): fieldtypes, page builders and house style.
16. [Privacy and data](privacy.md): what is sent where, and what is kept.
17. [Troubleshooting](troubleshooting.md): common problems and fixes.

## Requirements

- PHP 8.3 or later, with GD.
- Statamic 6.30 or later. Roles and permissions need Statamic Pro.
- An API key for Anthropic, OpenAI or Google Gemini.
- A queue worker, or PHP-FPM on the `sync` queue.
- Laravel's scheduler, for the hourly stock photos cleanup.
- Optional: a Shutterstock API plan for paid stock photos, billed by Shutterstock to your account.
