<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Images\ContainerAssetSink;
use NineteenNinetyFour\Ghostwriter\Settings;

/**
 * The last touches on a new entry built from a draft: what the model entries
 * agree on place by place (settings, links, nested items, the dressing on
 * rich text), and a striped placeholder wherever an image belongs but none
 * is chosen. An existing entry being edited keeps its own.
 */
class HouseFinish
{
    public function __construct(private EntryLayouts $layouts, private Settings $settings) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, notes: array<int, string>}
     */
    public function finish(array $data, Schema $schema, Pattern $pattern, ?string $id, string $title): array
    {
        $notes = [];

        $house = $this->layouts->apply($data, $schema, $pattern->house, $id, $title);
        $data = $house->data;

        if ($note = $house->note()) {
            $notes[] = $note;
        }

        if ($this->settings->placeholderImages()) {
            $placeholders = new Placeholders(new ContainerAssetSink, $pattern->filled);
            $data = $placeholders->fill($data, $schema);

            if ($note = $placeholders->note()) {
                $notes[] = $note;
            }
        }

        return ['data' => $data, 'notes' => $notes];
    }

    /**
     * Once the entry has an ID: the links to itself the house style held back.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function linkToSelf(array $data, Schema $schema, Pattern $pattern, string $id, string $title): array
    {
        return $this->layouts->linkToSelf($data, $schema, $pattern->house, $id, $title);
    }
}
