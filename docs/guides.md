# The voice guide and image style guide

Both guides are plain markdown files in your project. Ghostwriter writes the first version from your site, and you correct it. Both are read every time Ghostwriter writes, or finds or makes an image.

## The voice guide

**Ghostwriter → Voice guide.** It describes how your site sounds, with real examples from your entries:

- who is talking, and to whom
- the attitude and the warmth
- how pieces are shaped: openings, headings, endings, length
- the words you use, and the ones you never do

### Writing it

Tick the collections to read under **Read the site again**, then **Rescan and rewrite** (or **Generate the guide** the first time). Ghostwriter reads the newest published entries in those collections, spread evenly across them, and writes the guide in a minute or so. Rescanning replaces the guide, including any edits you've made.

To control how much it reads, see `voice.max_entries` and the other `voice.*` keys in [Configuration](configuration.md).

### Changing it

- **Edit** it directly in the markdown editor, then **Save**.
- Or **Ask for a change** in plain words, for example "We never say solutions. Add that.", then **Update the guide**. Ghostwriter rewrites the guide with the change, and says what it did.

The guide is `resources/ghostwriter/voice.md`. Commit it with your project so every environment writes the same way.

## The image style guide

**Ghostwriter → Image style.** It describes what your pictures look like, collection by collection: photography or illustration, subjects, composition, light, colour, mood, what would look wrong, and how to search for one that fits.

### Writing it

Tick the collections to look at, then **Generate from your images**. Ghostwriter looks at the images used by the newest published entries in each collection, in every image field, including those inside page-builder blocks, a few from each field. It writes a `##` section for each collection. A collection needs at least three images to describe.

The number of images looked at per collection is `images.guide_samples` (10 by default).

### Changing it

Edit it in the same editor as the voice guide. Keep a `## Collection title` heading for each collection: that is how Ghostwriter finds the part that applies to an image.

The guide is `resources/ghostwriter/imagery.md`.

### Where it is used

- Choosing what to **search for** when finding a photo, including the rule that a piece about an idea gets a concrete object that stands for the argument, not the activity it literally describes.
- **Picking** the photos that best fit, from the search results.
- **Making** an image.

See [Images](images.md).
