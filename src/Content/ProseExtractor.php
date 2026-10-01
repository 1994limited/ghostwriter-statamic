<?php

namespace NineteenNinetyFour\Ghostwriter\Content;

use Statamic\Contracts\Entries\Entry;

/**
 * Pulls the words an editor wrote out of an entry, whatever shape the
 * blueprint gives them: Bard documents, replicator sets, grids, plain text
 * and markdown. IDs, asset paths, links and other machine values are left
 * behind, because the point is to read the prose, not the plumbing.
 */
class ProseExtractor
{
    /** Keys whose values are never prose. */
    private const SKIP_KEYS = [
        'id', 'type', 'enabled', 'blueprint', 'template', 'layout', 'author', 'slug',
        'updated_by', 'updated_at', 'link', 'url', 'href', 'image', 'avatar', 'icon',
        'logo', 'logos', 'tint', 'fill', 'ground', 'gradient', 'width', 'style', 'anchor',
    ];

    /** ProseMirror node types that hold running text. */
    private const TEXT_NODES = ['paragraph', 'heading', 'blockquote', 'listItem', 'tableCell', 'tableHeader'];

    public function fromEntry(Entry $entry): string
    {
        $lines = [];

        $this->walk($entry->data()->except(['title'])->all(), $lines);

        return trim(implode("\n\n", array_values(array_unique($lines))));
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function walk(mixed $value, array &$lines, string|int|null $key = null): void
    {
        if (is_array($value)) {
            if ($this->isTextNode($value)) {
                $text = trim($this->nodeText($value));

                if ($text !== '') {
                    $lines[] = ($value['type'] === 'heading' ? '## ' : '').$text;
                }

                return;
            }

            foreach ($value as $childKey => $child) {
                if (is_string($childKey) && in_array($childKey, self::SKIP_KEYS, true)) {
                    continue;
                }

                $this->walk($child, $lines, $childKey);
            }

            return;
        }

        if (is_string($value) && $this->looksLikeProse($value)) {
            $lines[] = trim($value);
        }
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isTextNode(array $value): bool
    {
        return isset($value['type']) && is_string($value['type']) && in_array($value['type'], self::TEXT_NODES, true);
    }

    /**
     * @param  array<mixed>  $node
     */
    private function nodeText(array $node): string
    {
        if (isset($node['text']) && is_string($node['text'])) {
            return $node['text'];
        }

        $text = '';

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $text .= $this->nodeText($child);
            }
        }

        return $text;
    }

    /**
     * A sentence or a headline, not a handle, a path or a reference.
     */
    private function looksLikeProse(string $value): bool
    {
        $value = trim($value);

        if (mb_strlen($value) < 12 || ! str_contains($value, ' ')) {
            return false;
        }

        return ! preg_match('/^(entry::|asset::|https?:\/\/|#|\/)/', $value);
    }
}
