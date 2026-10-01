<?php

namespace NineteenNinetyFour\Ghostwriter\Images;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Ai\Agents\PhotoScout;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Settings;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Support\Str;

/**
 * What the image button on a field can do: find a photograph, have one
 * made, and keep the result as an asset in the field's own container and
 * folder. Everything is matched to the pictures already in that place on
 * the site's other entries.
 */
class FieldImages
{
    public function __construct(private ImageStudio $images, private StockSearch $stock, private ImageryGuide $guide, private Studio $studio, private Settings $settings) {}

    /**
     * Three searches for a photograph that suits the slot, chosen by the
     * model from the words around it. Without a model, the page's title.
     *
     * @return array<int, string>
     */
    public function searchTerms(FieldSlot $slot): array
    {
        if (! $this->studio->configured()) {
            return array_values(array_filter([mb_strtolower($slot->title)]));
        }

        $style = $this->guide->for($slot->collection->title());
        $prompt = "Page title: {$slot->title}\n\nThe picture goes in: {$slot->label()}\n\n"
            .($slot->blockText !== '' ? "Words in that part of the page:\n\n{$slot->blockText}\n\n" : '')
            ."The whole page:\n\n".($slot->pageText !== '' ? $slot->pageText : '(nothing written yet)')
            .($style !== '' ? "\n\nThe site's own description of its images in this section:\n\n{$style}" : '');

        $answer = (new PhotoScout($this->promptFile('photo-researcher')))->prompt($prompt, provider: $this->settings->provider(), model: $this->settings->model(), timeout: 60)->text;

        return self::terms($answer) ?: array_values(array_filter([mb_strtolower($slot->title)]));
    }

    /**
     * Searches, from a reply or from what a person typed: up to three,
     * separated by semicolons or new lines.
     *
     * @return array<int, string>
     */
    public static function terms(string $text): array
    {
        $terms = array_map(fn (string $term) => trim(preg_replace('/[^\p{L}\p{N} \'-]+/u', ' ', $term) ?? '', " \t-'"), preg_split('/[;\n]+/u', $text) ?: []);
        $terms = array_values(array_unique(array_filter(array_map(fn (string $term) => mb_strtolower(preg_replace('/\s+/u', ' ', $term) ?? ''), $terms))));

        return array_slice($terms, 0, 3);
    }

    /**
     * @param  array<int, string>  $terms
     * @return array<int, array<string, mixed>>
     */
    public function shortlist(FieldSlot $slot, array $terms): array
    {
        $references = $slot->references();

        return $this->images->shortlistFor($terms, $references, $this->images->shapeOf($references[0] ?? null), $slot->title, $this->guide->for($slot->collection->title()));
    }

    /**
     * @return array{content: string, mime: string}
     */
    public function make(FieldSlot $slot, string $direction = '', ?string $source = null): array
    {
        $image = $this->images->make($slot->references(), $slot->title, '', $slot->label(), $direction, $source, $this->guide->for($slot->collection->title()));

        return ['content' => $image->content(), 'mime' => $image->mime()];
    }

    /**
     * Keep a picture as an asset in the field's container and folder.
     *
     * @param  array<string, mixed>  $meta  Title, credit and the like, kept on the asset.
     */
    public function keep(FieldSlot $slot, string $content, string $extension, array $meta = []): Asset
    {
        $container = AssetContainer::find($slot->field['container'])
            ?? throw new InvalidArgumentException("The asset container \"{$slot->field['container']}\" no longer exists.");

        $extension = $extension === 'jpeg' ? 'jpg' : preg_replace('/[^a-z0-9]/', '', strtolower($extension));
        $name = Str::slug((string) ($meta['title'] ?? '')) ?: (Str::slug($slot->title) ?: 'image');
        $path = ltrim($slot->folder().'/'.$name.'-'.Str::lower(Str::random(6)).'.'.$extension, '/');

        $container->disk()->put($path, $content);

        $asset = $container->makeAsset($path);
        $asset->data(array_filter($meta, fn ($value) => $value !== null && $value !== ''))->save();

        return $asset;
    }

    private function promptFile(string $name): string
    {
        $published = resource_path("ghostwriter/prompts/{$name}.md");

        return trim((string) File::get(File::exists($published) ? $published : __DIR__."/../../resources/prompts/{$name}.md"));
    }
}
