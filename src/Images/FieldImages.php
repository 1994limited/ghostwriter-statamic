<?php

namespace NineteenNinetyFour\Ghostwriter\Images;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoContext;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoResults;
use Statamic\Contracts\Assets\Asset;

/**
 * What the image button on a field can do: find a photograph, have one
 * made, and keep the result as an asset in the field's own container and
 * folder. Everything is matched to the pictures already in that place on
 * the site's other entries.
 *
 * Finding is core's PhotoFinder: it chooses searches from the words around
 * the field (or runs the ones typed), judges the results against the page
 * and the pictures already there, and runs a second round when none fit.
 */
class FieldImages
{
    public function __construct(private ImageStudio $images, private PhotoFinder $finder, private GuideStore $guides) {}

    /**
     * Photographs for the slot.
     *
     * @param  array<int, string>  $terms  What the person typed; empty to have searches chosen from the page.
     */
    public function find(FieldSlot $slot, array $terms = []): PhotoResults
    {
        $references = array_values(array_filter(array_map(fn (Asset $asset) => (string) $asset->contents(), $slot->references())));

        return $this->finder->find($this->context($slot), $references, $terms === [] ? null : $terms);
    }

    public function context(FieldSlot $slot): PhotoContext
    {
        return PhotoContext::make(
            title: $slot->title,
            label: $slot->label(),
            blockText: $slot->blockText,
            pageText: $slot->pageText,
            shape: $this->images->shapeOf($slot->references()[0] ?? null),
            style: $this->guides->guide(Guide::IMAGERY)->section($slot->collection->title()),
        );
    }

    /**
     * @return array{content: string, mime: string}
     */
    public function make(FieldSlot $slot, string $direction = '', ?Image $source = null): array
    {
        $image = $this->images->make($slot->references(), $slot->title, '', $slot->label(), $direction, $source, $this->guides->guide(Guide::IMAGERY)->section($slot->collection->title()));

        return ['content' => $image->data, 'mime' => $image->mime];
    }

    /**
     * Keep a photograph from a library, named, titled and described from
     * what the library says it shows.
     */
    public function keepPhoto(FieldSlot $slot, PhotoFile $file, string $term = ''): Asset
    {
        $photo = $file->photo;
        $fallback = trim($term) !== '' ? $term : $slot->title;

        return $this->keep($slot, $file->content, $file->extension, [
            'title' => $photo->assetTitle($fallback),
            'alt' => $photo->alt($fallback),
            'credit' => $photo->credit,
            'credit_url' => $photo->creditUrl,
            'licence' => $photo->licence,
        ], $photo->filenameBase($fallback));
    }

    /**
     * Keep a picture as an asset in the field's container and folder.
     *
     * @param  array<string, mixed>  $meta  Title, alt text, credit and the like, kept on the asset.
     */
    public function keep(FieldSlot $slot, string $content, string $extension, array $meta = [], ?string $name = null): Asset
    {
        return ImageStudio::saveAsset($slot->field['container'], $slot->folder(), $name ?: ((string) ($meta['title'] ?? '') ?: $slot->title), $content, $extension, $meta);
    }
}
