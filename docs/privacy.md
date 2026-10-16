# Privacy and data

This page covers what Ghostwriter sends, where, and what it keeps.

## What is sent, and where

Ghostwriter only sends anything to a model when someone in the Control Panel starts something. It sends to the provider you chose, on your own account, straight to that provider's API, or to the gateway set in `base_urls`. The one thing that runs by itself and reaches outside your site is the weekly external link check, which is off unless you turn it on.

| When | Sent to | What |
| --- | --- | --- |
| Writing or editing | Your writing provider | The voice guide, the brief, the conversation, the current draft, the blueprint's fields, and one or two example entries |
| Filling in the brief | Your writing provider | Your quick details (or the plan idea), the kind's questions, and the collection's entry titles |
| Writing the voice guide | Your writing provider | Text from the newest published entries in the chosen collections |
| Learning or suggesting kinds | Your writing provider | Entry titles, how entries are built and how they open, and example entries |
| Writing the image style guide | Your writing provider | Small copies of images from the collection's entries |
| Content plan | Your writing provider | Entry titles and summaries from the chosen collections, and the plan |
| Finding a photo | Your writing provider, and the photo libraries | To the provider: words from the block and page, small copies of the images in the same place on other entries, and thumbnails of the results. To the libraries: the search words only |
| Making an image | Your image provider | A description, the image style guide, and up to three images from the same place on other entries, plus any image of your own you add |
| The SEO and link pass on a draft, and **Suggest links** | Your writing provider | The page's text, and a list of your site's page titles, URLs and summaries to choose links from |
| **Suggest edits** | Your writing provider | The page's text, its title, kind and date, the voice guide, and the candidates the free checks found. A link suggestion also sends up to ten of your pages' titles, URLs and summaries |
| Finish this page: **Write it for me**, **Write around it** and filling in a gap | Your writing provider | The page's text around the gap, the field it's for, and the voice guide |
| Comments: **Apply** | Your writing provider | Your comments, the blocks they're on, the conversation and the current draft |
| Stock photos (Shutterstock) | Shutterstock | Your search words, the photo you license, and, when you connect your account, the sign-in Shutterstock asks for on its own site |
| Checking links to other sites (weekly, off by default) | The sites your content links to | A request to each linked page, to see whether it's still there. Nothing from your site is sent with it |
| **Check & save** in Connections | That service | One small test call with the key you pasted (a list of one model, or a search for one photo) |

## What is not sent

- API keys, except each to its own service.
- User accounts, passwords or other personal data from Statamic. Names shown on shared conversations stay in your site.
- Entries beyond the samples listed above.
- Anything at all, until someone asks.

## Each provider's terms

Each provider's own terms decide how it handles what you send. In particular, on **Gemini's free tier**, Google may use what you send to improve its products; the paid tier doesn't. For client sites, use a paid account. See [API keys](api-keys.md#google-gemini-and-images).

With **OpenRouter**, every request, including images, passes through OpenRouter on its way to the model's company, so OpenRouter's [privacy policy](https://openrouter.ai/privacy) and data settings apply as well as that company's. A key from **Connect with OpenRouter** is kept encrypted with the other [Connections](connections.md#where-keys-are-kept).

## What is kept, and where

Almost all of it is files. The one database table is `ghostwriter_credentials`, for keys set up in Connections (made by `php artisan migrate`; without a database they go in an encrypted file instead). See [Where things are kept](configuration.md#where-things-are-kept) for the full list.

- **Conversations and drafts:** `storage/ghostwriter/sessions/`, one JSON file each, with who sent each message when conversations are shared. Removing a piece deletes its file.
- **Photo searches and made pictures:** `storage/ghostwriter/images/`, with the person who asked. Cleared after a day.
- **Guides, kinds and the plan:** `resources/ghostwriter/`.
- **Keys set up in Connections:** encrypted with your `APP_KEY`, in the `ghostwriter_credentials` table, or in `storage/ghostwriter/credentials/` without a database. Never shown again, only their last four characters. If `APP_KEY` changes, these keys can no longer be read: set them up again in Connections. Keys in `.env` aren't affected. See [Connections](connections.md#where-keys-are-kept).
- **Logs:** the provider, model, tokens and time of each call, never the words or keys. A reply that couldn't be read is logged whole only with `debug.log_replies` on.

Next: [Troubleshooting](troubleshooting.md).
