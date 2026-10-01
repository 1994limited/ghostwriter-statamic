<?php

namespace NineteenNinetyFour\Ghostwriter\Images;

use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\AssetContainer;
use Throwable;

/**
 * Marks where an image belongs but none has been chosen: the same diagonal
 * stripes, the usual sign of a picture still to come, so the page shows its
 * layout as it will be and nobody can mistake the placeholder for a real
 * image.
 *
 * An image "belongs" where the field is required, or where at least half of
 * the existing entries (for a block, half the existing blocks of that type)
 * have one. An optional picture, such as a background few pages use, is
 * left empty.
 *
 * One placeholder file is made per container and reused, so the asset
 * library does not fill up with copies.
 */
class Placeholders
{
    public const PATH = 'ghostwriter/image-placeholder.png';

    /** Share of existing entries or blocks with an image that makes one expected. */
    private const EXPECTED = 0.5;

    /** @var array<string, string|null> Placeholder asset path by container. */
    private array $assets = [];

    /** @var array<int, string> Where placeholders went, for the notes. */
    private array $filled = [];

    /**
     * @param  array<string, float>  $rates  How often each field is filled, from PatternFinder.
     */
    public function __construct(private array $rates = []) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    public function fill(array $data, array $schema, ?string $block = null, ?string $type = null): array
    {
        foreach ($schema as $spec) {
            $handle = $spec['handle'];
            $label = ($block ? "{$block}: " : '').($spec['display'] ?: $handle);
            $value = $data[$handle] ?? null;
            $expected = $this->expected($spec, ($type ? "{$type}." : '').$handle);

            if ($spec['type'] === 'assets') {
                // A field kept to PDFs or videos is no place for a picture.
                if (empty($value) && $expected && ($spec['images'] ?? true) && ($path = $this->assetFor($spec)) !== null) {
                    $data[$handle] = ($spec['max_files'] ?? null) === 1 ? $path : [$path];
                    $this->filled[] = $label;
                }

                continue;
            }

            if ($spec['kind'] !== 'blocks') {
                continue;
            }

            if (is_array($value) && $value !== []) {
                // Blocks in the draft: fill in each one's own image fields.
                foreach ($value as $i => $item) {
                    $set = is_array($item) ? ($spec['sets'][$item['type'] ?? ''] ?? null) : null;

                    if ($set) {
                        $data[$handle][$i] = $this->fill($item, $set['fields'], $block ?? $set['display'], $item['type']);
                    }
                }
            } elseif ($expected && $this->imagesOnly($spec) && ($item = $this->imageItem($spec, $label)) !== null) {
                // A replicator that holds nothing but images, such as a
                // gallery inside a block: one item, with the placeholder.
                $data[$handle] = [$item];
            }
        }

        return $data;
    }

    /**
     * @return array<int, string>
     */
    public function filled(): array
    {
        return array_values(array_unique($this->filled));
    }

    /**
     * Required, or filled on most of the entries (or blocks) like this one.
     * With no history to go by, only a required field.
     *
     * @param  array<string, mixed>  $spec
     */
    private function expected(array $spec, string $key): bool
    {
        return ($spec['required'] ?? false) || ($this->rates[$key] ?? 0) >= self::EXPECTED;
    }

    /**
     * A replicator whose every set is only an image field or two.
     *
     * @param  array<string, mixed>  $spec
     */
    private function imagesOnly(array $spec): bool
    {
        $sets = $spec['sets'] ?? [];

        foreach ($sets as $set) {
            foreach ($set['fields'] as $field) {
                if ($field['type'] !== 'assets') {
                    return false;
                }
            }
        }

        return $sets !== [];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>|null
     */
    private function imageItem(array $spec, string $label): ?array
    {
        foreach ($spec['sets'] ?? [] as $type => $set) {
            foreach ($set['fields'] as $field) {
                if ($field['type'] === 'assets' && ($field['images'] ?? true) && ($path = $this->assetFor($field)) !== null) {
                    $this->filled[] = $label;

                    return ['id' => bin2hex(random_bytes(4)), 'type' => $type, 'enabled' => true, $field['handle'] => ($field['max_files'] ?? null) === 1 ? $path : [$path]];
                }
            }
        }

        return null;
    }

    /**
     * The placeholder's path within the field's container, made on first use.
     *
     * @param  array<string, mixed>  $spec
     */
    private function assetFor(array $spec): ?string
    {
        $handle = (string) ($spec['container'] ?? '');

        if ($handle === '') {
            return null;
        }

        return $this->assets[$handle] ??= $this->make($handle);
    }

    private function make(string $container): ?string
    {
        $container = AssetContainer::find($container);

        if (! $container) {
            return null;
        }

        try {
            if ($container->asset(self::PATH)) {
                return self::PATH;
            }

            $container->disk()->put(self::PATH, $this->png());

            $asset = $container->makeAsset(self::PATH);
            $asset->data(['title' => 'Image to choose (placeholder from Ghostwriter)', 'alt' => 'Image to choose'])->save();

            return self::PATH;
        } catch (Throwable $exception) {
            Log::warning('Ghostwriter: the placeholder image could not be saved: '.$exception->getMessage());

            return null;
        }
    }

    /**
     * Soft grey diagonal stripes edge to edge, drawn in code.
     */
    public function png(): string
    {
        $width = 1600;
        $height = 1000;
        $band = 40;

        $image = imagecreatetruecolor($width, $height);
        $light = imagecolorallocate($image, 0xEE, 0xF0, 0xF3);
        $dark = imagecolorallocate($image, 0xDD, 0xE1, 0xE6);

        imagefill($image, 0, 0, $light);

        // Bands at 45 degrees, wide enough to read at any size the image is shown.
        for ($x = -$height; $x < $width; $x += $band * 2) {
            imagefilledpolygon($image, [$x, $height, $x + $band, $height, $x + $band + $height, 0, $x + $height, 0], $dark);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
