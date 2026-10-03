# Stock photos

Ghostwriter can put photos from paid stock libraries into your pages, licensed from **your own account** with the library, with your own key. It never licenses on your behalf, never holds a key of its own, and never sends your calls through anyone else's servers.

**Which libraries.** **Shutterstock (API plan required)**: licences are charged by Shutterstock to your own API plan. Getty Images and iStock are **coming**: their keys can be set now, and they switch on once a Ghostwriter update adds them. To try the whole flow without an account, use the **demo library** on a local site.

Free photos (Openverse, Unsplash, Pexels, Pixabay) work as before: see [Images](images.md). Every photo Ghostwriter puts into the site, free or paid, is recorded in the [stock image ledger](#the-stock-images-screen).

## How it works

1. **Find.** In the image dialog's **Find a photo** tab, **Search in** chooses where to look: *Free libraries*, one paid library, or *Everything*.
2. **Insert preview.** A paid photo doesn't go in as the real file. The field gets a **stand-in**: a striped image with the photo's shape, labelled "Getty Images 1234567 · preview, not licensed". It already has the photo's title, alt text and file name. The library's **watermarked preview** (its "comp") is kept privately and is shown only to people signed in to the Control Panel.
3. **License & replace.** Someone with the licence permission presses **License** on the field's badge (or in the asset editor, or on the Stock images screen), checks the cost and presses **License & replace**. The stand-in's file is swapped for the licensed photo. It is the same asset at the same path, so every page that uses it keeps it, with its alt text, title and focal point.
4. **Publish.** A page can't be published while it holds a preview that isn't licensed yet.

Why a stand-in? The libraries' terms allow previews for "test or sample" use only, never in anything publicly available, and a file in an asset container sits at a public address even on a draft. The stand-in holds nothing of the library's, so it is safe there. The real preview never is.

## Setting up a paid library

Add the keys from your account with the library to `.env`. Your keys stay on your site. Ghostwriter sends them only to the provider you chose, never to us.

| Library | Keys | Status |
| --- | --- | --- |
| Shutterstock | `SHUTTERSTOCK_API_KEY`, `SHUTTERSTOCK_API_SECRET` (your app's consumer key and secret) | Available; licensing needs **Connect account** |
| Getty Images and iStock | `GETTY_API_KEY`, `GETTY_API_SECRET` (an iStock key works here too) | Coming |

Getty and iStock keys come from your Getty Images or iStock account representative, under your own agreement.

### Shutterstock

Shutterstock licenses only through an **API plan**, which is separate from a shutterstock.com website plan.

1. Create an app at shutterstock.com/account/developers/apps. Its consumer key and secret go in `.env` as above. Searching works with those alone.
2. In the app's **Callback URL** field, add the host name and path the settings row shows, such as `cms.example.com/cp/ghostwriter/libraries/shutterstock/callback` (a host name and path, not a full address). It must match your site's `APP_URL`.
3. On the settings screen, press **Connect account** and sign in with the Shutterstock account that holds the API plan. Licences come from that account, for the whole site. **Disconnect** forgets the connection here; to revoke it at Shutterstock, delete the app.

The connection's tokens are kept encrypted with your app key in `storage/ghostwriter/library-tokens.json`, never in `content/`. If the connection is lost (the password changed, or the app was deleted), licensing says so, with **Connect again in Settings**.

Shutterstock's licence gives no preview licence for still images, so Ghostwriter never stores a Shutterstock preview: the Control Panel shows Shutterstock's own watermarked preview address, to signed-in editors only.

**Sandbox.** On a local site (`APP_ENV=local`), Shutterstock calls go to its sandbox, where licensing charges nothing and the "licensed" file is the watermarked preview. Set `GHOSTWRITER_SHUTTERSTOCK_SANDBOX=false` to use the live API there, or `true` to use the sandbox elsewhere. Sign-in always goes to shutterstock.com.

Then open **Ghostwriter → Settings → Stock photos**:

- each library shows whether its keys are set (never the keys), and **Check connection** says which account it is connected as and what it can still buy;
- **Use …** switches a library on or off in "Search in";
- **Default source** is where "Search in" starts until someone chooses another; each person's last choice is remembered;
- **Include editorial images by default** (off);
- What happens when a page with an unlicensed preview is published is set under **Finish this page** on the same screen: **When a page with something unfinished is published**, **Block** (the default) or **Warn**. See [Finish this page](finish-this-page.md).

### The demo library

"Demo stock (no charge)" is a pretend paid library: it charges nothing, calls nobody, and draws its photos. It is there to try the whole flow, for training and screenshots. It is on by itself when `APP_ENV=local`; set `GHOSTWRITER_STOCK_DEMO=true` to use it on another test site. **It never runs in production**, whatever the setting.

## Finding and inserting

- Each result card shows the library ("Getty", "iStock", "Unsplash") and the cost: **Free**, or what the library says before licensing ("1 download", "3 credits"), or **Paid** when it doesn't say. Ghostwriter never shows a cash price: none of these libraries gives one before you license.
- **Editorial** images (news, events, well-known people) carry a chip with their restrictions. They may not be used to sell or promote anything, and most pages are commercial use, so they are left out unless **Include editorial images** is ticked. The checkbox shows only when the library you're searching (or one of those in **Everything**) can return editorial images; the free libraries and the demo library never do. The (i) beside it explains what editorial use allows.
- Paid results come in the library's own order. Ghostwriter doesn't ask a model to judge them: the libraries' licences forbid using their photos or their captions with AI.
- **Use this** puts a free photo in; **Insert preview** puts a paid photo in as a preview: "Preview added. Only signed-in editors see the photo; license it before publishing."

## Previews in your pages

- **On the field.** An assets field holding a preview shows a **Preview · not licensed** badge beside **Find a photo**, with the preview's thumbnail. It opens the preview, with **License**, or for people without the licence permission, "Ask a manager to license" and **Request licence**.
- **In the asset editor.** The asset gets a **Stock photo** panel: its state, library, ID, order and credit, with the same buttons.
- **In Live Preview.** Signed-in editors see the watermarked preview in place of the stand-in, wherever the template outputs the image's address in an `img` tag's `src` or `srcset` (Glide addresses included). Images set as a CSS background, built by JavaScript, or served from a CDN under another file name show the stand-in. A shared preview link opened by someone who isn't signed in always shows the stand-in. Set `stock.live_preview` to `false` to turn this off.
- **How long a preview lasts.** The libraries allow a preview to be kept for a while (Getty and iStock: 30 days from when it was downloaded). After that Ghostwriter deletes it; the stand-in stays, so drafts don't break, and the badge reads "Preview expired". **Refresh preview** downloads it again, once, when someone clicks it.

## Licensing

**License & replace** shows:

- the licence option, with a choice when your account offers several (a size, standard or extended);
- what it uses from your account: "Uses 1 of your 742 remaining downloads (Premium Access, resets 1 Nov)";
- any editorial restrictions, and notices about who may use the file (an iStock standard licence is for one person at a time; Premium Access images must be removed from shared storage if your agreement ends);
- the **credit line**, with "If this page is news, a blog post or other editorial use, show this credit next to the image." Ghostwriter stores the credit on the asset (`credit`, `credit_url`); your templates must print it where it is needed.

The licence is bought **once**. Pressing again, or a second person pressing at the same time, is refused. If the library's answer is lost (a timeout, say), Ghostwriter says "We couldn't confirm the purchase. Ghostwriter will check with the library in a few minutes; don't buy it again." and settles it later from the library's own record of your licences.

The licensed file is stored exactly as the library delivered it, never re-encoded, so its embedded copyright and IDs stay, as the licences require. If the library sends a different kind of file from the stand-in, Ghostwriter keeps the licence and asks you to replace the image by hand; **Download again and replace** fetches it again without buying again.

If a CDN sits in front of your asset container, it may keep showing the stand-in until its cache expires. Give the images folder a short cache lifetime, or purge it after licensing.

## Permissions

Licensing spends from your account, so it is a permission of its own: **License stock images from paid libraries** (`license stock images`), given to nobody by default (super users have it). Inserting a preview needs only Ghostwriter access and upload rights to the container, as finding a free photo does. See [Permissions](permissions.md).

## Publishing

When an entry is saved as published (now, or on a future date), and it holds a preview that isn't licensed yet, the save is refused with a message on the image field:

> This is a Getty preview, not licensed yet. License it, or choose another image, before publishing.

Saving it unpublished always works. This covers publishing a working copy and scheduled entries too. It's the same guard, and the same message, as for everything else [Finish this page](finish-this-page.md) finds, so a page with a preview and a fact still to add gets one refusal naming both. Set **When a page with something unfinished is published** to **Warn** (or `GHOSTWRITER_ON_UNFINISHED_PUBLISH=warn`) to publish with a warning instead.

Drafts that Ghostwriter writes start unpublished on new entries (see [Use this draft](writing.md#use-this-draft)), so a draft with a preview in it can be saved straight away.

## The Stock images screen

**Ghostwriter → Stock images** lists every stock photo in the site, in tabs: **Previews** (requested licences first), **Licensed**, **Failed** and **All**. Each row shows the image, its library and ID, where it is used (with links), its state, cost, who licensed it and when, its credit line and restrictions. Actions: **License**, **Reconcile** (for a licence whose outcome wasn't known), **Remove preview** (only once no page uses it), and **Licence record** (the whole record as JSON). **Export CSV** gives date, library, ID, title, state, order ID, cost, licence type, who licensed it and when, credit line, restrictions and where it is used, for finance and audits.

The Overview shows "N stock previews to license" while there are some, in amber when one is on a live page or its preview has expired; the dashboard widget says so in a line.

Records are one YAML file each in `content/ghostwriter/stock/` (`ghostwriter.stock_path`): commit them with your site. They are never deleted, even when an image is: its licence stays on file. Where each image is used is updated whenever an entry is saved; `php please ghostwriter:stock-usages` rescans every entry, for content changed outside the Control Panel.

## Cleanup

`ghostwriter:stock-cleanup` runs **hourly** on Laravel's scheduler (make sure `php artisan schedule:run` runs every minute, as Laravel asks). It:

- deletes each preview when the library's preview period ends;
- deletes stand-ins no page has used for 30 days (`stock.unused_preview_days`) and marks their records removed; a stand-in still in use is never deleted;
- settles licences whose outcome wasn't known, after ten minutes, from the library's own records.

## AI and paid photos

Getty's and iStock's licences forbid using their photos, or their captions and keywords, with AI, licensed photos included. So Ghostwriter never sends a paid library's photo to a model: not to rank search results, not as a reference when finding or making a picture, and not as a sample for the image style guide. It also leaves out any file named or credited as Getty Images or iStock, however it got onto the site. If you use other AI tools on your site, have them leave these images alone too.

## Configuration

| Key | Default | |
| --- | --- | --- |
| `stock_path` | `content/ghostwriter/stock` | The ledger |
| `stock.keys.*` | from `.env` | `GETTY_API_KEY`, `GETTY_API_SECRET`, `SHUTTERSTOCK_API_KEY`, `SHUTTERSTOCK_API_SECRET` |
| `stock.demo` | `null` (on when `APP_ENV=local`) | The demo library; never in production |
| `stock.shutterstock_sandbox` | `null` (on when `APP_ENV=local`) | Shutterstock's sandbox |
| `stock.on_publish` | `null` | Replaced by `publish.on_unfinished` ([Finish this page](finish-this-page.md)); still read when that isn't set. |
| `stock.default_source` | `null` (the screen; free if that is blank) | `free`, `everything` or a library's ID |
| `stock.include_editorial` | `null` (the screen; off if that is blank) | |
| `stock.live_preview` | `true` | Show the preview in Live Preview |
| `stock.unused_preview_days` | `30` | When unused previews are removed |
