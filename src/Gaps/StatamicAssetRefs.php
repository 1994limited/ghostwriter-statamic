<?php

namespace NineteenNinetyFour\Ghostwriter\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetRefs;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;

/**
 * The assets in a Statamic field's value, as the stock ledger's
 * UsageScanner reads them:
 *
 * - an assets field holds paths in its own container (one, or a list);
 * - Bard holds images inline as `asset::container::path` on image nodes,
 *   and its sets hold assets fields of their own, by path in the set
 *   field's container;
 * - Bard saved as HTML, and markdown, hold `asset::container::path` in an
 *   image's address.
 */
class StatamicAssetRefs implements AssetRefs
{
    private const REFERENCE = '/asset::([A-Za-z0-9_.-]+)::([^\s"\'\)\]<>]+)/';

    public function in(mixed $value, Field $field): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if ($field->kind === Kind::RichText) {
            return $this->unique($this->inRichText($value, $field));
        }

        return $this->unique($this->inField($value, $field));
    }

    /**
     * An assets field's paths, in its container.
     *
     * @return list<AssetRef>
     */
    private function inField(mixed $value, Field $field): array
    {
        $container = is_scalar($field->meta['container'] ?? null) ? (string) $field->meta['container'] : '';
        $found = [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }

            if (preg_match(self::REFERENCE, $item, $match) === 1) {
                $found[] = AssetRef::statamic($match[1], $match[2]);
            } elseif (str_contains($item, '::')) {
                [$volume, $path] = explode('::', $item, 2);
                $found[] = AssetRef::statamic($volume, $path);
            } elseif ($container !== '') {
                $found[] = AssetRef::statamic($container, $item);
            }
        }

        return $found;
    }

    /**
     * @return list<AssetRef>
     */
    private function inRichText(mixed $value, Field $field): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (! is_array($decoded)) {
                return $this->references($value);
            }

            $value = $decoded;
        }

        if (! is_array($value)) {
            return [];
        }

        $found = [];

        foreach ($value as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'image' && is_string($node['attrs']['src'] ?? null)) {
                array_push($found, ...$this->references($node['attrs']['src']));

                continue;
            }

            // A Bard set: its own fields, by the set's config.
            if (($node['type'] ?? null) === 'set' && is_array($node['attrs']['values'] ?? null)) {
                $values = $node['attrs']['values'];
                $set = is_string($values['type'] ?? null) ? $field->set($values['type']) : null;

                foreach ($set?->fields ?? [] as $inner) {
                    if (array_key_exists($inner->handle, $values)) {
                        array_push($found, ...$this->in($values[$inner->handle], $inner));
                    }
                }

                continue;
            }

            if (is_array($node['content'] ?? null)) {
                array_push($found, ...$this->inRichText($node['content'], $field));
            }
        }

        return $found;
    }

    /**
     * @return list<AssetRef>
     */
    private function references(string $text): array
    {
        preg_match_all(self::REFERENCE, $text, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => AssetRef::statamic($match[1], $match[2]), $matches);
    }

    /**
     * @param  list<AssetRef>  $assets
     * @return list<AssetRef>
     */
    private function unique(array $assets): array
    {
        $unique = [];

        foreach ($assets as $asset) {
            $unique[$asset->key()] ??= $asset;
        }

        return array_values($unique);
    }
}
