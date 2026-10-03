<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetRefs;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\AssetRefsContract;
use NineteenNinetyFour\Ghostwriter\Gaps\StatamicAssetRefs;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's AssetRefsContract against StatamicAssetRefs: an assets field's
 * path in its container, and a Bard image node's `asset::` reference.
 * Also an assets field inside a Bard set, which the stock ledger counts.
 */
final class AssetRefsTest extends TestCase
{
    use AssetRefsContract;

    protected function assetRefs(): AssetRefs
    {
        return new StatamicAssetRefs;
    }

    protected function assetInAField(): array
    {
        $field = Field::fromSpec(['handle' => 'cover', 'type' => 'assets', 'kind' => 'reference', 'images' => true, 'container' => 'assets', 'max_files' => 1]);

        return [$field, 'covers/garden.jpg', AssetRef::statamic('assets', 'covers/garden.jpg')];
    }

    protected function assetInlineInRichText(): array
    {
        return [$this->bard(), [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before the planting.']]],
            ['type' => 'image', 'attrs' => ['src' => 'asset::assets::inline/border.jpg', 'alt' => 'A border']],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After.']]],
        ], AssetRef::statamic('assets', 'inline/border.jpg')];
    }

    public function test_an_image_in_a_bard_set_is_found_in_the_sets_container(): void
    {
        $found = $this->assetRefs()->in([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Words.']]],
            ['type' => 'set', 'attrs' => ['id' => 's1', 'values' => ['type' => 'figure', 'photo' => ['figures/bench.jpg']]]],
        ], $this->bard());

        $this->assertSame(['photos::figures/bench.jpg'], array_map(fn (AssetRef $asset) => $asset->key(), $found));
    }

    private function bard(): Field
    {
        return Field::fromSpec(['handle' => 'body', 'type' => 'bard', 'kind' => 'richtext', 'sets' => [
            'figure' => ['display' => 'Figure', 'fields' => [['handle' => 'photo', 'type' => 'assets', 'kind' => 'reference', 'images' => true, 'container' => 'photos']]],
        ]]);
    }
}
