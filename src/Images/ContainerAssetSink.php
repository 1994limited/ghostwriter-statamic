<?php

namespace NineteenNinetyFour\Ghostwriter\Images;

use Illuminate\Support\Facades\Log;
use NineteenNinetyFour\Ghostwriter\Core\Images\AssetSink;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use Statamic\Facades\AssetContainer;
use Throwable;

/**
 * Where core's striped placeholder goes on a Statamic site: the image
 * field's own asset container, at `ghostwriter/image-placeholder.png`,
 * titled and described as a placeholder. One file is made per container
 * and reused, so the asset library does not fill up with copies.
 *
 * The page preview saves nothing, so it uses one that only takes the
 * placeholder already in the container (`new ContainerAssetSink(create:
 * false)`): where there is none yet, the image is left empty.
 */
class ContainerAssetSink implements AssetSink
{
    public const PATH = 'ghostwriter/image-placeholder.png';

    /** @var array<string, string|null> The placeholder's path by container. */
    private array $assets = [];

    public function __construct(private bool $create = true) {}

    public function placeholder(Field $field, callable $png): string|int|null
    {
        $handle = is_scalar($field->meta['container'] ?? null) ? (string) $field->meta['container'] : '';

        if ($handle === '') {
            return null;
        }

        return $this->assets[$handle] ??= $this->make($handle, $png);
    }

    public function value(Field $field, string|int $reference): mixed
    {
        return ($field->meta['max_files'] ?? null) === 1 ? $reference : [$reference];
    }

    /**
     * A replicator set holding the placeholder, with an ID of its own as
     * every set has.
     */
    public function block(Field $builder, string $set, Field $image, mixed $value): ?array
    {
        return ['id' => bin2hex(random_bytes(4)), 'type' => $set, 'enabled' => true, $image->handle => $value];
    }

    /**
     * @param  callable(): string  $png
     */
    private function make(string $handle, callable $png): ?string
    {
        $container = AssetContainer::find($handle);

        if (! $container) {
            return null;
        }

        try {
            if ($container->asset(self::PATH)) {
                return self::PATH;
            }

            if (! $this->create) {
                return null;
            }

            $container->disk()->put(self::PATH, $png());

            $asset = $container->makeAsset(self::PATH);
            $asset->data(['title' => Placeholders::TITLE, 'alt' => Placeholders::ALT])->save();

            return self::PATH;
        } catch (Throwable $exception) {
            Log::warning('Ghostwriter: the placeholder image could not be saved: '.$exception->getMessage());

            return null;
        }
    }
}
