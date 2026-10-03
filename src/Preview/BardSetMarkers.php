<?php

namespace NineteenNinetyFour\Ghostwriter\Preview;

use NineteenNinetyFour\Ghostwriter\Core\Preview\BlockMap;
use NineteenNinetyFour\Ghostwriter\Core\Preview\MappedBlock;
use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewData;
use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewMarkers;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;

/**
 * Bard's sets as blocks of their own on the preview's map. Core marks a
 * Bard value's text and its sections but skips the sets in it (a pull
 * quote, a photo, a stats grid), so the page would outline only the whole
 * field. Here each set becomes a child block, keyed after core's blocks:
 *
 * - inside the section it sits in (by where core's section markers end),
 *   or the field (or block) the Bard value belongs to;
 * - its text values carry markers like any block's, at the end;
 * - its images are listed by file name, so an image-only set (a photo) is
 *   found by its address, as Glide's URLs end in the file name.
 *
 * Runs on the preview's copy only, after PreviewMarkers::mark(); the hash
 * (of the unmarked data) is unchanged.
 */
final class BardSetMarkers
{
    /** @var list<MappedBlock> */
    private array $added = [];

    private int $next = 0;

    public function mark(PreviewData $preview, Schema $schema): PreviewData
    {
        $this->added = [];
        $this->next = 0;

        foreach ($preview->map->keys() as $key) {
            if (preg_match('/^b(\d+)$/', $key, $m)) {
                $this->next = max($this->next, (int) $m[1]);
            }
        }

        $data = $preview->data;

        foreach ($schema as $field) {
            if (! array_key_exists($field->handle, $data)) {
                continue;
            }

            if ($this->isBard($field, $data[$field->handle])) {
                $data[$field->handle] = $this->bard($data[$field->handle], $field, $field->handle);
            } elseif ($field->isBuilder() && is_array($data[$field->handle])) {
                foreach ($data[$field->handle] as $i => $block) {
                    $set = is_array($block) ? $field->set((string) ($block['type'] ?? '')) : null;

                    foreach ($set?->fields ?? [] as $child) {
                        if (array_key_exists($child->handle, $block) && $this->isBard($child, $block[$child->handle])) {
                            $data[$field->handle][$i][$child->handle] = $this->bard($block[$child->handle], $child, $field->handle.'/'.$i.'/'.$child->handle);
                        }
                    }
                }
            }
        }

        if ($this->added === []) {
            return $preview;
        }

        $map = $preview->map->toArray();

        foreach ($this->added as $block) {
            $map[] = $block->toArray();
        }

        return new PreviewData($data, BlockMap::fromArray($map), $preview->hash, $preview->planId, $preview->draftVersion);
    }

    private function isBard(Field $field, mixed $value): bool
    {
        return $field->kind === Kind::RichText && $field->sets !== [] && is_array($value) && array_is_list($value);
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @return array<int, mixed>
     */
    private function bard(array $nodes, Field $field, string $path): array
    {
        // Where core's markers are: the owner's (the field's or block's) and where each section ends.
        $owner = null;
        $ends = [];

        foreach ($nodes as $index => $node) {
            foreach (PreviewMarkers::decode($this->text($node)) as $found) {
                if (str_starts_with((string) $found['key'], 's')) {
                    $ends[] = [$index, $found['key']];
                } else {
                    $owner = $found['key'];
                }
            }
        }

        foreach ($nodes as $index => $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'set' || ! is_array($node['attrs']['values'] ?? null)) {
                continue;
            }

            $values = $node['attrs']['values'];
            $type = is_scalar($values['type'] ?? null) ? (string) $values['type'] : '';
            $set = $field->set($type);

            if ($set === null || ($node['attrs']['enabled'] ?? true) === false) {
                continue;
            }

            $parent = $owner;

            foreach ($ends as [$end, $section]) {
                if ($end >= $index) {
                    $parent = $section;

                    break;
                }
            }

            $nodes[$index]['attrs']['values'] = $this->set($values, $set, $type, $parent, $path.'/#'.($node['attrs']['id'] ?? $index));
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function set(array $values, Set $set, string $type, ?string $parent, string $path): array
    {
        $key = 'b'.(++$this->next);
        $fields = [];
        $assets = [];
        $anchors = [];

        foreach ($set->fields as $f => $child) {
            if (! array_key_exists($child->handle, $values)) {
                continue;
            }

            $value = $values[$child->handle];
            $marker = PreviewMarkers::encode($key.'.'.$f);

            if ($child->files) {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    if (is_string($item) && $item !== '' && ! in_array($name = basename(str_replace('::', '/', $item)), $assets, true)) {
                        $assets[] = $name;
                    }
                }

                $fields[$f] = $child->handle;

                continue;
            }

            if (in_array($child->kind, [Kind::Text, Kind::LongText], true) && is_string($value) && trim($value) !== '') {
                $values[$child->handle] = PreviewMarkers::markText($value, $marker);
                $fields[$f] = $child->handle;
                $anchors[] = self::anchor($value);
            } elseif ($child->kind === Kind::Rows && is_array($value)) {
                foreach ($value as $r => $row) {
                    foreach ($child->fields as $cell) {
                        if (is_array($row) && is_string($row[$cell->handle] ?? null) && trim($row[$cell->handle]) !== '' && in_array($cell->kind, [Kind::Text, Kind::LongText], true)) {
                            $value[$r][$cell->handle] = PreviewMarkers::markText($row[$cell->handle], $marker);
                        }
                    }
                }

                $values[$child->handle] = $value;
                $fields[$f] = $child->handle;
            }
        }

        $this->added[] = new MappedBlock($key, MappedBlock::BLOCK, $path, $set->label !== '' ? $set->label : $type, $parent, [], $fields, $assets, array_values(array_filter($anchors)), $type);

        return $values;
    }

    /**
     * The first eight words of a value, for the locator's text fallback.
     */
    private static function anchor(string $value): string
    {
        $words = preg_split('/\s+/u', trim(strip_tags(strtok($value, "\n") ?: '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words) >= 2 ? implode(' ', array_slice($words, 0, 8)) : '';
    }

    /**
     * @param  mixed  $node
     */
    private function text($node): string
    {
        if (! is_array($node)) {
            return '';
        }

        $text = is_string($node['text'] ?? null) ? $node['text'] : '';

        foreach ((array) ($node['content'] ?? []) as $child) {
            $text .= $this->text($child);
        }

        return $text;
    }
}
