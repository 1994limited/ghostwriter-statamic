# The content plan

**Ghostwriter → Content plan** is a list of entries worth writing, grouped by collection, the newest first. Each idea has a title, why it is worth writing, and notes on the angle, who it is for and what it should say.

## Asking what is missing

Under **Ask what is missing**:

1. Tick the collections to read.
2. Optionally, steer it: "More for owners." "White label."
3. **Suggest ideas.** Ghostwriter reads everything in those collections, and what is already on the plan, and suggests entries the site doesn't have. It takes a minute or so.
4. A box opens with the suggestions, all ticked. Untick the ones you don't want, then **Add N to the plan**.

Unticked suggestions are kept as dismissed, so they are not suggested again. **Drop them all** discards the lot. With none ticked, the button reads **Add none, dismiss the rest**.

Closing the box (or pressing Esc) doesn't throw the suggestions away: they wait, with a **N suggestions waiting** card at the top of the plan. **Review** opens them again. Only **Drop them all** discards them.

The number of ideas asked for each time is `plan.suggestions` (8 by default).

## Adding your own

Under **Add your own**, give a working title, choose the collection, and add notes if you like. Notes feed the quick brief, so a line or two helps.

## Writing from an idea

**Draft this** opens a new entry in that collection with Ghostwriter open and the brief filling itself in from the idea. Pressing **Start writing** marks the idea as started.

Started pieces show under **In progress** at the top of the plan with their stage and a **Resume** button; **Back to ideas** returns one to the list. A piece counts as finished only once its entry is saved: put into the form and not saved, it stays in progress. Then the idea moves to **finished and dismissed**, with a link to the entry. Removing a piece's conversation puts its idea back.

**Not this one** dismisses an idea. Dismissed ideas can be put back with **Put back**; finished ones can't, as that would make a second copy of a piece already written. **Clear the list** removes every open idea; **Delete all dismissed** forgets what was dismissed.

## Where the plan is kept

The plan is `resources/ghostwriter/ideas.yaml`, so it can be versioned and shared across environments.
