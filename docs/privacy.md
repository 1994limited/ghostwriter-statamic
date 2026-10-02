# Privacy and data

This page covers what Ghostwriter sends, where, and what it keeps.

## What is sent, and where

Ghostwriter only sends anything when someone in the Control Panel starts something. It sends to the provider you chose, on your own account, straight to that provider's API, or to the gateway set in `base_urls`.

| When | Sent to | What |
| --- | --- | --- |
| Writing or editing | Your writing provider | The voice guide, the brief, the conversation, the current draft, the blueprint's fields, and one or two example entries |
| Filling in a quick brief | Your writing provider | The title and notes, the kind's questions, and the collection's entry titles |
| Writing the voice guide | Your writing provider | Text from the newest published entries in the chosen collections |
| Learning or suggesting kinds | Your writing provider | Entry titles, how entries are built and how they open, and example entries |
| Writing the image style guide | Your writing provider | Small copies of images from the collection's entries |
| Content plan | Your writing provider | Entry titles and summaries from the chosen collections, and the plan |
| Finding a photo | Your writing provider, and the photo libraries | To the provider: words from the block and page, small copies of the images in the same place on other entries, and thumbnails of the results. To the libraries: the search words only |
| Making an image | Your image provider | A description, the image style guide, and up to three images from the same place on other entries, plus any image of your own you add |

## What is not sent

- API keys, except each to its own service.
- User accounts, passwords or other personal data from Statamic. Names shown on shared conversations stay in your site.
- Entries beyond the samples listed above.
- Anything at all, until someone asks.

## Each provider's terms

Each provider's own terms decide how it handles what you send. In particular, on **Gemini's free tier**, Google may use what you send to improve its products; the paid tier doesn't. For client sites, use a paid account. See [API keys](api-keys.md#google-gemini-and-images).

## What is kept, and where

All of it is files; Ghostwriter adds no database tables. See [Where things are kept](configuration.md#where-things-are-kept) for the full list.

- **Conversations and drafts:** `storage/ghostwriter/sessions/`, one JSON file each, with who sent each message when conversations are shared. Removing a piece deletes its file.
- **Photo searches and made pictures:** `storage/ghostwriter/images/`, with the person who asked. Cleared after a day.
- **Your own images uploaded for a picture under a draft:** `storage/ghostwriter/uploads/`.
- **Guides, kinds and the plan:** `resources/ghostwriter/`.
- **Logs:** the provider, model, tokens and time of each call, never the words or keys.

Next: [Troubleshooting](troubleshooting.md).
