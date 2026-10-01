<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use NineteenNinetyFour\Ghostwriter\Blueprints\HouseStyle;
use NineteenNinetyFour\Ghostwriter\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Settings;

/**
 * The last touches on a new entry built from a draft: what the model entries
 * agree on place by place (settings, links, nested items, the dressing on
 * rich text), and a striped placeholder wherever an image belongs but none
 * is chosen. An existing entry being edited keeps its own.
 */
class HouseFinish
{
    public function __construct(private HouseStyle $style, private Settings $settings) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $pattern  From PatternFinder.
     * @return array{data: array<string, mixed>, notes: array<int, string>}
     */
    public function finish(array $data, array $schema, array $pattern, ?string $id, string $title): array
    {
        $notes = [];

        if (! empty($pattern['house'])) {
            $toFill = [];
            $data = $this->style->apply($data, $schema, $pattern['house'], $toFill, ['id' => $id, 'title' => $title]);

            if ($toFill) {
                $notes[] = 'Still to set by hand, as it differs from page to page: '.implode('; ', array_unique($toFill)).'.';
            }
        }

        if ($this->settings->placeholderImages()) {
            $placeholders = new Placeholders($pattern['filled'] ?? []);
            $data = $placeholders->fill($data, $schema);

            if ($placeholders->filled()) {
                $notes[] = 'A striped placeholder marks each image still to pick: '.implode('; ', $placeholders->filled()).'. Replace them before publishing.';
            }
        }

        return ['data' => $data, 'notes' => $notes];
    }

    /**
     * Once the entry has an ID: the links to itself the house style held back.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $pattern
     * @return array<string, mixed>
     */
    public function linkToSelf(array $data, array $schema, array $pattern, string $id, string $title): array
    {
        return empty($pattern['house']) ? $data : $this->style->linkToSelf($data, $schema, $pattern['house'], $id, $title);
    }
}
