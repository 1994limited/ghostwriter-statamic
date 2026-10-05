# Ghostwriter documentation

Ghostwriter learns how your site writes and how its pages are built, then drafts entries in that voice and fits them into your page layouts, from a short brief and a conversation, beside the entry form in Statamic's Control Panel. It links new pages to the rest of the site, finds or makes images to match, points out what's left to finish, suggests edits to older pages, and keeps a plan of what the site is missing.

## Setting up

1. [Installation](installation.md): requirements, installing, the queue, updating.
2. [Get started](getting-started.md): the seven setup steps in the Control Panel.
3. [API keys](api-keys.md): keys for Claude, ChatGPT or Gemini, gateways, retries, and the free photo libraries. [Connections](connections.md): setting every key up in the Control Panel, and where they are kept.
4. [Permissions](permissions.md): who can use and manage Ghostwriter, and shared conversations.
5. [Configuration](configuration.md): the settings screen, `config/ghostwriter.php`, where things are kept, prompts and logging.

## Using Ghostwriter

6. [Writing a new entry](writing.md): the brief, the conversation, the draft, its layouts and preview, comments, links to your other pages, the search title, description and address, and **Use this draft**.
7. [Editing an existing entry](editing.md): changing an entry in conversation.
8. [The voice guide and image style guide](guides.md): how your site sounds, and what its pictures look like.
9. [Kinds of content](kinds.md): teaching Ghostwriter the things you write often.
10. [Images](images.md): finding and making images, ranking, alt text and placeholders.
11. [Stock photos](stock-photos.md): paid libraries, previews, License & replace, the publish rule and the Stock images screen.
12. [Finish this page](finish-this-page.md): facts to add, links to choose and images to pick, the guide, and the publish rule.
13. [Suggest edits and Content to revisit](suggest-edits.md): pages worth a look, ranked by free checks, and suggested edits to step through.
14. [The content plan](content-plan.md): ideas for what to write next.
15. [The Overview and widget](dashboard.md): what is in progress, at a glance.

## Reference

16. [How Ghostwriter reads your fields](fields.md): fieldtypes, page builders and house style.
17. [Privacy and data](privacy.md): what is sent where, and what is kept.
18. [Troubleshooting](troubleshooting.md): common problems and fixes.
19. [Scripted replies for end-to-end tests](testing.md): for Ghostwriter's own browser tests, on local sites only.

## Requirements

- PHP 8.3 or later, with GD.
- Statamic 6.30 or later. Roles and permissions need Statamic Pro.
- An API key for Anthropic, OpenAI or Google Gemini.
- A queue worker, or PHP-FPM on the `sync` queue.
- Laravel's scheduler, for the hourly stock photos cleanup and the daily pass that keeps Content to revisit and the list of pages to link to current.
- Optional: a Shutterstock API plan for paid stock photos, billed by Shutterstock to your account.
