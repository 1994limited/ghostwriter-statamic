# Get started

**Ghostwriter → Get started** walks you through setup, one step at a time. The list down the left shows each step as **Done**, **To do**, **Optional** or **Working…**, with a progress bar above. The bar counts the five required steps; the two optional ones don't hold it back. The page opens on the first required step still to do. Click any step to go straight to it; the address updates (`#step-3`) so a step can be linked to. Every step can be skipped and redone later.

A model call (writing a guide, looking at a collection) runs in the background. You can leave the page while it works; it carries on.

## 1. Connect a model

Shows which provider Ghostwriter writes with, and whether its API key is set. If it isn't, add the key to `.env` and reload. See [API keys](api-keys.md).

## 2. Choose where it writes

The collections Ghostwriter writes for, and the collections it reads to learn your voice. Only the collections it writes for get the **Write with Ghostwriter** button.

If you may change Ghostwriter's settings, choose them right here: tick **Write for it** and **Learn the voice from it** for each collection, then **Save these collections**. Ticking every collection means all of them, including any added later. A list set in `config/ghostwriter.php` can't be changed here and is shown as such. Everyone else sees which collections are chosen.

## 3. Learn your voice

Ghostwriter reads the newest published entries from the chosen collections and writes a [voice guide](guides.md#the-voice-guide). Read it, and correct anything that isn't right. Everything Ghostwriter writes follows it.

Without a voice guide, Ghostwriter still writes, but in a plain voice rather than yours.

## 4. Teach it your kinds of content

Ghostwriter looks at each collection and suggests the kinds of content in it, such as "Case study" or "Service page". The suggestions are shown on this step, each with why it's worth teaching and a few of the entries it was found in. **Learn this** on the ones you write often; each gets its own brief. **Learn all** asks first, then learns every suggestion in a collection, one after another. See [Kinds of content](kinds.md).

Every collection can be written for without this; it then uses a general brief.

## 5. Describe your images (optional)

Ghostwriter looks at the pictures your entries use and writes an [image style guide](guides.md#the-image-style-guide), so the photos it finds and the images it makes belong beside them. A collection needs at least three images to describe.

## 6. Plan what to write (optional)

Ghostwriter reads the whole site and suggests entries it is missing. Keep the good ones on the [content plan](content-plan.md).

## 7. Write something

Pick a collection and start a new entry with Ghostwriter beside it. See [Writing a new entry](writing.md).

## Hiding Get started

Once the required steps are done, the dashboard says **You're set up** in one line until Get started is put away. Hiding it hides it for everyone, so it is done by someone who may change Ghostwriter's settings:

- **Hide** on the dashboard card, or **Hide Get started** on the Get started page, removes it from the dashboard and the Ghostwriter menu.
- To bring it back, use **Show Get started** at the foot of the dashboard, or turn on **Show Get started** in the addon's settings and save. That setting acts on Ghostwriter's own state and is not stored.
