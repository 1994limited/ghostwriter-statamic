# Images

Ghostwriter helps with images in two places: on every assets field in an entry form, and under a draft in the writing panel.

## The image button on a field

On every assets field in a collection Ghostwriter writes for, a small **Ghostwriter** button (the ghost) sits in the field's header beside its own controls, on create and edit screens, for people with the Ghostwriter permission.

It isn't shown on fields whose validation only allows other kinds of file (for example `mimes:pdf`), or on fields with no container.

Click it to choose an image for that one field: **Find a photo** or **Make one**. Where only one of them is available, the dialog shows just that one; with neither, it says which keys to add.

### How it decides what fits

- **Words.** Ghostwriter reads the replicator block the field is in first, then the rest of the form as it stands, saved or not. A picture inside a "Charity partner" block is chosen for that partner, not for the page as a whole.
- **Style.** It looks at the images already in the **same place** on the collection's other entries: the same field, in the same kind of set, looking back through up to 150 entries. If none use that exact set, it looks at the same field in a set of the same family, for example `link_grid_left` for `link_grid_right`. The [image style guide](guides.md#the-image-style-guide) adds the words.
- **Shape.** Landscape, portrait or square, from those images.

### Find a photo

Searches free photo libraries: Openverse (no key needed) and Unsplash, Pixabay and Pexels when you've added their keys. See [API keys](api-keys.md#free-photo-libraries).

1. Type what the picture should show, or leave it empty and Ghostwriter chooses three searches from the block and page. Separate your own searches with semicolons.
2. **Find photos.** The model looks at the results beside the images already used there, and marks the best ones **Best match**. The rest follow under **View more**. Where no other entry has an image in that place yet, there is nothing to compare with: the top result of each search comes first, with no badge, and a line under the search says they weren't compared.
3. Click the photo you want, or its **Use this** button.

### Make one

Needs an OpenAI or Gemini key (see [API keys](api-keys.md)).

1. Optionally, say what it should show. Leave it blank and Ghostwriter decides from the block and page.
2. Optionally, add **an image of your own to put in it**, such as a product shot. It is used as it is, not redrawn.
3. **Make image.** It takes a minute or two, matching the style of the images already in that place.
4. **Use this**, or **Make another**.

Ghostwriter never draws a real company's logo from memory. For a logo, add the logo file to the field yourself, or give it as your own image in step 2.

### What happens when you choose one

- The image is saved as an asset in the field's own **container and folder**, or beside the images already there, with a title.
- It goes straight into the field, as if you'd chosen it from the browser. Save the entry as usual.
- If the field held a **striped placeholder**, the placeholder comes out. In a field that holds only one image, the new image replaces the old one.
- In a field that holds several images and already has as many as it allows, the image isn't added. It is still saved in the container, and Ghostwriter says so: remove an image from the field to make room, then choose it from the container.

### Credits

A photograph's photographer, library and licence are saved on the asset as `credit`, `credit_url` and `licence`, for example "Jane Doe on Unsplash". Add fields with those handles to the container's blueprint to see and print them.

Openverse photos are public domain or CC0. Unsplash, Pixabay and Pexels photos are under their own free licences, which ask you to credit the photographer where you can.

### Requests are private

A search or a picture being made belongs to the person who started it. Made pictures waiting to be used are kept for a day, then cleared away.

## Images with a draft

Under a draft in the writing panel, the **Images** section lists each image field the entry normally has: the top-level ones, and those in the draft's blocks that other entries fill in.

- Three photographs are offered for each field as soon as the draft is written, chosen by the writer's own searches and ranked against the images already in that place. **View more** shows the rest. Click a photo, or its **Use this** button, to choose it.
- **Find a photo** runs your own search; **Choose from the photos for** another field, or **Use the same image as** another field, where fields share a picture.
- Asking in the conversation works too: "find images for this" offers options, "add the images" puts the best match straight in.
- **Make image** appears here too, with the same keys as on the field button, and can be given an image of your own to build the picture around.

Chosen images go into the form with **Use this draft**, in the field's container and folder.

## Placeholders

When a draft is put into a **new** entry, image fields it leaves empty get a striped "image to choose" placeholder, but only where the collection's entries usually have an image there (or the field is required). Optional images, such as a background most entries leave empty, are left alone, as are fields that take no pictures.

- The placeholder is one shared asset, `ghostwriter/image-placeholder.png`, in the field's container.
- The notes above the form list every field that has one.
- Replace them with the image button before publishing.

Turn this off with **Mark images still to choose** in the settings.
