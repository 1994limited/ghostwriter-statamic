# The dashboard and widget

## Ghostwriter's dashboard

**Tools → Ghostwriter** shows:

- **Get started**, while setup isn't finished: the next step, with **Continue** and **Hide**. See [Get started](getting-started.md#hiding-get-started).
- **Four tiles**: the voice guide and image style guide (in place or not, and when last updated), ideas waiting on the content plan, and pieces in progress. Click one to open it.
- **Collections**: every collection Ghostwriter writes for, with its entry count and how many kinds it has. Kinds show as small labels; hover for the description, click to edit. Each collection has:
  - **Write**, to start a new entry with Ghostwriter.
  - A **Kinds** menu: **Teach it a kind** and **Suggest kinds**.
  - **N suggested kinds to review**, when there are suggestions. See [Kinds of content](kinds.md).
- **In progress**: pieces being written, with their stage (Writing, Waiting on you, Draft ready, In the form, Editing, and so on). Click one to carry on, or **Remove** it. A piece leaves this list once its entry has been saved. Finished pieces are under **Show finished**.

The settings cog in the header opens the addon's settings.

## The dashboard widget

Add Ghostwriter to Statamic's own dashboard in `config/statamic/cp.php`:

```php
'widgets' => [
    ['type' => 'ghostwriter', 'limit' => 5],
],
```

It shows:

- Get started progress, until it is complete or hidden.
- How many pieces are in progress, and how many ideas are waiting.
- The latest pieces in progress, with their stage; long titles are truncated.
- **Write something**, a menu of collections to start a new entry in, and **Open Ghostwriter**.

`limit` is how many pieces it lists (5 by default, up to 20); `title` renames it. People without the Ghostwriter permission see nothing.
