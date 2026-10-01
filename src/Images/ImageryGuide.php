<?php

namespace NineteenNinetyFour\Ghostwriter\Images;

use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;

/**
 * The site's image style guide: what its pictures look like, section by
 * section, in words. It is to images what the voice guide is to writing, and
 * is read whenever search words are chosen, photographs are ranked or an
 * image is made. One markdown file in the project, with a `##` heading for
 * each collection.
 */
class ImageryGuide extends VoiceGuide
{
    public function path(): string
    {
        return (string) config('ghostwriter.images.guide_path', resource_path('ghostwriter/imagery.md'));
    }

    /**
     * What the guide says about one collection, or the whole guide where it
     * is not divided that way. Empty when there is no guide.
     */
    public function for(string $collectionTitle): string
    {
        $guide = $this->get();

        if (preg_match('/^##\s+'.preg_quote($collectionTitle, '/').'\s*$(.*?)(?=^##\s|\z)/imsu', $guide, $m)) {
            return trim($m[1]);
        }

        return str_contains($guide, "\n## ") ? '' : trim($guide);
    }
}
