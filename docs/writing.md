# Writing a new entry

This page covers writing a new entry with Ghostwriter: choosing what to write, the brief, the conversation, the draft, and putting it into the form.

## Starting

Open a collection's **Create Entry** screen and click **Write with Ghostwriter**, to the left of **Save & Publish**. The button appears on new entries in the collections Ghostwriter writes for, for people with the Ghostwriter permission. On an existing entry the button is **Edit with Ghostwriter**; see [Editing an existing entry](editing.md).

You can also start from:

- **Write** beside a collection on the [Overview](dashboard.md);
- **Write something** on the [dashboard widget](dashboard.md#the-dashboard-widget);
- **Write with Ghostwriter** in a collection's actions menu, on its entry list;
- **Draft this** on an idea in the [content plan](content-plan.md).

Each opens a new entry with Ghostwriter already open. Ghostwriter opens in a full-width slide-over; the entry form stays underneath.

## What are you writing?

Choose what this entry is:

- **From the content plan:** ideas waiting to be written. Choosing one fills in the brief card from the idea.
- **A kind you taught it**, such as "Project story". It asks that kind's questions and models the entry on its examples. See [Kinds of content](kinds.md).
- **Something like what is already here:** kinds Ghostwriter found by grouping the collection's entries by how they are built. Choosing one models the new entry on those.
- **Something else:** a general brief for anything.
- **Or carry on with:** pieces already under way, with who started each one.
- **Teach a kind:** teach Ghostwriter a new kind, from entries you pick.

![The kind chooser: a learned kind, From the content plan, Something else and Or carry on with](images/writing-choose.png)

## The brief

There is no form to fill in. Once you've chosen what you're writing, the conversation opens and Ghostwriter asks for the quick details in one message: **"What’s it called, and what should it say? A line or two is plenty."** Reply with a working title and a few notes (the angle, who it's for, a point or two to make), and press **Send** or **⌘↵**.

From your reply Ghostwriter fills in the whole brief for that kind, with one model call, and shows it in the conversation as a **brief card**:

- **Working title**, then each of the kind's questions with its answer, all of them editable;
- **Model it on**, with the entries to follow ticked: a learned kind's own examples, or those of the kind you chose. Tick up to six; with none ticked, Ghostwriter goes by the brief and how this collection is usually written.

Anything only you know stays in `[square brackets]`, such as `[Add: the client and what changed after launch]`. Ghostwriter never makes up facts about your organisation, its projects or its figures: a figure or a quote you didn't give is left in brackets for you.

Change any answer in the card, then:

- **Looks right, start writing** stores the brief on the piece and starts writing. Anything still in brackets is fine: it becomes a gap in the draft, which [Finish this page](finish-this-page.md) picks up.
- **Try again** fills the brief in once more. The answers you changed are kept as you wrote them.

The card is a labelled region: a screen reader hears "The brief is filled in. Check it, then start writing." when it arrives, and the keyboard lands on it.

![The brief card in the conversation, with a working title, the kind's questions and Looks right, start writing](images/writing-brief.png)

**Draft this** on an idea in the [content plan](content-plan.md) skips the question: the card arrives already filled in from the idea, ready to check.

If the brief can't be filled in, Ghostwriter says so with **Try again**; or reply with a little more about the piece and it fills the brief in from everything you've said.

> Ghostwriter uses only facts from the brief and the conversation for anything about your organisation. It doesn't invent figures, quotes or client names. Widely known facts about the subject itself it may state, and says so in its reply.

## The conversation

Ghostwriter writes on the first turn whenever it can. If it needs something only you know, it asks first, at most three questions. The panel makes that plain: the question is headed **Ghostwriter needs your answer**, the reply box says **Your turn: answer the questions above and the draft follows.** (or **Your turn: answer above to carry on.** once there is a draft), and **Just draft it with what you have** has it write now and mark the gaps.

![Ghostwriter asking three questions, with Ghostwriter needs your answer and Just draft it with what you have](images/writing-questions.png)

Once the writing has started, the brief card collapses to **Show the brief**. Open it to read or change the brief, then **Save the brief**: nothing is rewritten straight away, and Ghostwriter works from the new brief from your next message. A piece carried on later, or a colleague's shared conversation, shows the card in the same place.

Once there is a draft, ask for changes in plain words:

- "Make the opening shorter."
- "Add a section on cost, after the process."
- "Less formal."

Press **⌘↵** (or **Ctrl+↵**) to send. While it works, a line and a timer say what it is doing ("Filling in the brief…", "Reading the brief…", "Thinking it through…", "Writing. Long drafts take a while…"); a draft usually takes a minute or two. Replies are shown formatted, with their lists and bold. A reply that changed the draft ends with a line such as **Draft written · 286 words** or **Draft updated · 222 → 236 words (+14)**. With reduced motion on, the spinners and the pulsing dot stand still.

If a turn fails, see ["That didn't work"](troubleshooting.md#that-didnt-work).

## The draft

The draft sits on the right, under three tabs: **Preview**, **Blocks** and **Text**. Ghostwriter remembers your choice in this browser.

- **Preview** shows the draft as a page of your site, rendered with the site's own templates. It is the first tab once there is a draft, for new entries and for [edits](editing.md). See [The Preview tab](#the-preview-tab).
- **Blocks** lays the draft out the way the entry is built: its fields, and its page-builder blocks in order. **Text** shows just the words, read straight through.
- **Click any writing (or Tab to it) to change it.** It's saved when you leave it; **Esc** puts back what was there. **Enter** finishes a one-line field; in longer text it starts a new line. Rich text stays rich: bold, links and lists are kept.
- **Edit YAML** opens the whole draft as YAML, to add, move or remove blocks. Most people never need it.

The word count is at the top. Images are chosen on the entry's own fields once the draft is in the form; see [Images with a draft](images.md#images-with-a-draft).

![A page-builder draft in Blocks view, with one text block being edited](images/writing-draft.png)

The panel follows the Control Panel's dark mode.

![The same draft in dark mode](images/writing-draft-dark.png)

### The Preview tab

**Preview** renders the draft through your site's templates, exactly as **Use this draft** would fill the form, in a frame beside the conversation. The bar above the page shows its title and address, and **Preview · not saved**.

- **Nothing is saved.** No entry is created or changed, and no image is added to your assets: it uses Statamic's Live Preview, with the draft held only for the preview. Image placeholders and [stock previews](stock-photos.md) show as they would on the page.
- **Hover the page** to see its blocks: each is outlined with its name ("Hero", "Call to action"), and a set inside Bard or a section of rich text is outlined dashed, with the block it sits in ("Pull quote · in What the practice asked for").
- **Desktop** and **Phone** switch the page's width. In a narrow panel, Desktop shows the full-width page scaled to fit.
- **It follows your changes.** After you change writing in Blocks or Text, use **Edit YAML**, choose an image, or Ghostwriter revises the draft, the page renders again when you come back to Preview (or 0.8 seconds after the change while it's showing). The old page stays, dimmed, until the new one has loaded, at the same place on the page.
- **Links on the page do nothing**, so the preview never navigates away. Forms can't be sent, and third-party scripts (analytics, tag managers, chat widgets) don't run.
- **If the page can't render**, Preview says so in words ("The page template couldn't render this draft: …"), with **Show blocks instead** and **Try again**. Super users also see the error and the template. The draft itself is fine: **Blocks**, **Text** and **Use this draft** all still work, and the piece opens on Blocks next time in this browser tab. A page that takes longer than 8 seconds is given up on; if an earlier version is showing, it stays, with "This page is slow to render; showing the last version".
- **No Preview tab** means the collection has no pages on the site (no route), or the preview is switched off (`preview.enabled`).

The tabs are reachable by keyboard: Tab to the selected one, then the arrow keys move between them. The frame is titled for screen readers ("Preview of the draft: …"), and the outlines are visual only.

### The preview, for site developers

The preview is a Live Preview request, so everything that already treats Live Preview differently does here too: `{{ live_preview }}` is true, static caching and the `{{ cache }}` tag are skipped, and drafts render. To tell a Ghostwriter preview apart from Statamic's own, use `{{ live_preview:ghostwriter }}`:

```antlers
{{ unless live_preview }}
    {{ partial:analytics }}
{{ /unless }}

{{ if live_preview:ghostwriter }}
    {{# A draft from Ghostwriter: no comments widget, no view counter #}}
{{ /if }}
```

- **Third-party scripts are blocked** on Ghostwriter's preview pages by a Content-Security-Policy (`script-src 'self' 'unsafe-inline' 'unsafe-eval'; connect-src 'self'; form-action 'none'; frame-ancestors 'self'; base-uri 'self'`), added beside any policy your site or Statamic (on multisite) already sends: the stricter rule wins. If your own scripts come from a CDN, allow it with `preview.script_hosts` (`GHOSTWRITER_PREVIEW_SCRIPT_HOSTS=https://cdn.example.com`).
- **The entry may never have been saved.** Its id starts `gw-preview-`, and `{{ collection:next }}`, `previous`, `older` and `newer` work for it. In a structured collection its URL is the one it will have: under the parent chosen in the form, or at the top.
- **The text carries invisible markers** (Unicode tag characters at the end of each value) that the panel reads and removes as soon as the page loads, to match the page to the draft's blocks. They don't change how the text looks, or what filters such as `title`, `upper` or `widont` do. A filter that cuts text short (`truncate`) may cut one off; that block is then found by its words instead.
- **Chrome warns** in the console that a frame with `allow-scripts` and `allow-same-origin` "can escape its sandboxing". That is expected: the frame is your own site, as in Statamic's Live Preview, and the policy above keeps it to your own scripts.
- **The frame must be allowed on the same origin.** If your web server sends `X-Frame-Options: DENY` or `frame-ancestors 'none'` for every page, the preview (and Statamic's Live Preview) can't show; allow `SAMEORIGIN`.

## Use this draft

**Use this draft** puts the draft into the entry form underneath, field by field. Anything the draft doesn't cover keeps what was in the form.

- **Nothing is saved or published.** Check the form over, then save as you normally would.
- **Drafts start unpublished.** On a new entry, or one that isn't published yet, the form's **Published** toggle is switched off, so you can save straight away and the entry goes live only when you switch it on. The message says "Ghostwriter drafts start unpublished. Switch on Published when you're ready." An entry that is already published keeps its toggle as it is. To leave the toggle alone everywhere, set `drafts_unpublished` to `false` in `config/ghostwriter.php` (or `GHOSTWRITER_DRAFTS_UNPUBLISHED=false`).
- Using it again replaces the fields it covers.

When the draft leaves something only you can finish (a fact it didn't have, a link to choose, an image placeholder), [Finish this page](finish-this-page.md) takes over: the count appears beside Save, the fields are highlighted, and the guide opens on the first gap. Anything else worth knowing is listed in one notice above the form, under "Draft added to the form. Check it over, then save.", until you close it, such as fields and blocks the draft used that this blueprint doesn't have, and options that don't exist, which were left out. Without either, a short message says the draft went in.

![The entry form after Use this draft: the count beside Save, the highlighted fields and the guide on the first gap](images/writing-used.png)

## Carrying on later

The conversation is saved, and the panel carries on with the piece you were on:

- **On the same screen**, after **Use this draft** or closing the panel, **Write with Ghostwriter** opens the same piece again.
- **Reloading the page** opens it too: the address names the piece (`?ghostwriter=…`).
- **Later**, open it from **In progress** on the Overview, **Or carry on with** in the panel, or **Resume** on the content plan. A draft put into the form but not saved stays in all three.

**Start over** goes back to **What are you writing?** for a new piece. The earlier conversation isn't deleted; it stays on the Overview until it is removed.

A piece leaves **In progress** once its entry has been saved. Putting the draft into the form isn't enough.

## Sharing conversations

With Statamic Pro and more than one user, conversations are shared with everyone who may use Ghostwriter, so a colleague can pick a piece up where you left it. Each message shows who sent it, and the brief card is in the thread. See [Shared conversations](permissions.md#shared-conversations).

Next: [Editing an existing entry](editing.md).
