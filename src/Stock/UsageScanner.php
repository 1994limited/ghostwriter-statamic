<?php

namespace NineteenNinetyFour\Ghostwriter\Stock;

use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;
use Statamic\Contracts\Entries\Entry;

/**
 * Finds the ledger's assets in an entry's data. Statamic keeps no index of
 * where an asset is used, so the data is walked: assets fields hold a path
 * in their container, Bard images `asset::container::path`, and other
 * fields `container::path`. Replicator and Bard sets, grids and groups are
 * walked too, so the field is reported by its path ("page_builder.2.image").
 *
 * A bare path is matched to the ledger record of that path; when two
 * containers hold the same path, the field's own container on the
 * blueprint decides.
 */
class UsageScanner
{
    private const IMAGE = '/\.(jpe?g|png|webp|gif|avif)$/i';

    public function __construct(private StockImageStore $store) {}

    /**
     * Every ledger asset in the data, with its field path and a label.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{asset: AssetRef, field: string, label: string|null}>
     */
    public function scan(Entry $entry, array $data): array
    {
        $records = $this->records();

        if ($records === []) {
            return [];
        }

        $byKey = [];
        $byPath = [];

        foreach ($records as $image) {
            $byKey[$image->asset->volume.'::'.$image->asset->path] = $image->asset;
            $byPath[$image->asset->path][] = $image->asset;
        }

        $found = [];

        foreach ($this->strings($data) as [$field, $value]) {
            $asset = null;

            if (str_starts_with($value, 'asset::')) {
                $value = substr($value, strlen('asset::'));
            }

            if (str_contains($value, '::')) {
                $asset = $byKey[$value] ?? null;
            } elseif (isset($byPath[$value])) {
                $asset = count($byPath[$value]) === 1 ? $byPath[$value][0] : $this->inFieldsContainer($entry, $field, $byPath[$value]);
            }

            if ($asset !== null) {
                $found[$asset->key().'|'.$field] = ['asset' => $asset, 'field' => $field, 'label' => $this->label($entry, $field)];
            }
        }

        return array_values($found);
    }

    /**
     * The records whose assets can be in use: any that isn't removed.
     *
     * @return array<int, StockImage>
     */
    private function records(): array
    {
        $states = array_values(array_diff(StockImage::STATES, [StockImage::REMOVED]));

        return $this->store->query(new StockImageQuery($states, perPage: 500))->images;
    }

    /**
     * Each string in the data that could be an image, with its path.
     *
     * @return \Generator<int, array{0: string, 1: string}>
     */
    private function strings(mixed $value, string $path = ''): \Generator
    {
        if (is_string($value)) {
            // A field holding several assets is one field, whichever place.
            if ($path !== '' && preg_match(self::IMAGE, $value)) {
                yield [(string) preg_replace('/(\.\d+)+$/', '', $path), $value];
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        // A Bard image node: its source is an attribute, the field is the Bard field.
        if (($value['type'] ?? null) === 'image' && ! isset($value['id']) && is_string($value['attrs']['src'] ?? null)) {
            yield [$path, $value['attrs']['src']];

            return;
        }

        foreach ($value as $key => $item) {
            // Inside Bard, nodes and their contents aren't fields of their own.
            $inBard = in_array($key, ['content', 'attrs', 'values', 'marks'], true) || (is_int($key) && $this->isNode($item));
            $next = $inBard && $path !== '' ? $path : ($path === '' ? (string) $key : $path.'.'.$key);

            yield from $this->strings($item, $next);
        }
    }

    private function isNode(mixed $item): bool
    {
        // Replicator sets carry an ID; ProseMirror nodes don't.
        return is_array($item) && is_string($item['type'] ?? null) && ! isset($item['id']) && (isset($item['content']) || isset($item['attrs']) || isset($item['text']));
    }

    /**
     * Of several assets at the same path, the one in the field's own container.
     *
     * @param  array<int, AssetRef>  $candidates
     */
    private function inFieldsContainer(Entry $entry, string $field, array $candidates): ?AssetRef
    {
        $handle = (string) Str::of($field)->explode('.')->last();
        $top = (string) Str::of($field)->explode('.')->first();
        $config = $entry->blueprint()?->field($handle)?->config() ?? [];

        if (! isset($config['container']) && $top !== $handle) {
            $config = $this->nestedConfig($entry->blueprint()?->field($top)?->config() ?? [], $handle);
        }

        foreach ($candidates as $candidate) {
            if (($config['container'] ?? null) === $candidate->volume) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * A field's config somewhere inside a replicator's, Bard's or grid's.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function nestedConfig(array $config, string $handle): array
    {
        foreach ($config as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (($value['handle'] ?? null) === $handle && is_array($value['field'] ?? null)) {
                return $value['field'];
            }

            if (($found = $this->nestedConfig($value, $handle)) !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * "Hero image", or "Page builder: Image" for a field inside a set.
     */
    private function label(Entry $entry, string $field): string
    {
        $steps = array_values(array_filter(explode('.', $field), fn (string $step) => ! ctype_digit($step)));
        $top = $steps[0] ?? $field;
        $display = $entry->blueprint()?->field($top)?->display() ?: Str::headline($top);

        if (count($steps) < 2) {
            return (string) $display;
        }

        return $display.': '.Str::headline((string) end($steps));
    }
}
