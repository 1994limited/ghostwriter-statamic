# Privacy and data

## What is sent, and where

Ghostwriter only sends anything when someone in the Control Panel asks it to. It sends to the provider you chose, on your own account, through the Laravel AI SDK.

| When | Sent to | What |
| --- | --- | --- |
| Writing or editing | Your writing provider | The voice guide, the brief, the conversation, the current draft, the blueprint's fields, and one or two example entries |
| Filling in a quick brief | Your writing provider | The title and notes, the kind's questions, and the collection's entry titles |
| Writing the voice guide | Your writing provider | Text from the newest published entries in the chosen collections |
| Learning or suggesting kinds | Your writing provider | Entry titles, how entries are built, how they open, and example entries |
| Writing the image style guide | Your writing provider | Small copies of images from the collection's entries |
| Content plan | Your writing provider | Entry titles and summaries from the chosen collections, and the plan |
| Finding a photo | Your writing provider; the photo libraries | To the provider: words from the block and page, small copies of the images in the same place on other entries, and thumbnails of the search results. To the libraries: the search words only |
| Making an image | Your image provider | A description, the image style guide, and up to three images from the same place on other entries, plus any source image you add |

## What is not sent

- API keys, except to the service each belongs to.
- User accounts, passwords or personal data from Statamic.
- Anything, until someone asks.

## Each provider's terms

Each provider's own terms decide how they handle what you send. In particular, on **Gemini's free tier**, Google may use what you send to improve its products; the paid tier doesn't. For client sites, use a paid account. See [API keys](api-keys.md#google-gemini).

## What is kept, and where

- Conversations and drafts are JSON files in `storage/ghostwriter/sessions/`. Remove a piece from the dashboard to delete its conversation.
- Images made but not yet used, and source images uploaded for them, are kept in `storage/ghostwriter/images/` for a day.
- Kind suggestions, job state and whether Get started is hidden are JSON files beside them.
- Guides, kinds and the plan are files in `resources/ghostwriter/`.
