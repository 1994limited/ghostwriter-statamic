<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

/**
 * The reverse of MarkdownToBard: a stored Bard document as the markdown a
 * writer would have typed. Used to show the model real entries from the site
 * in the same form it is asked to write in.
 *
 * Sets placed inside a Bard field are kept only when they carry text worth
 * reading (a pull quote becomes a block quote); images, buttons and other
 * furniture are dropped.
 */
class BardToMarkdown
{
    /**
     * @param  array<int, mixed>  $nodes
     */
    public function convert(array $nodes): string
    {
        $blocks = [];

        foreach ($nodes as $node) {
            if (is_array($node) && ($block = $this->block($node)) !== '') {
                $blocks[] = $block;
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function block(array $node, int $indent = 0): string
    {
        $content = (array) ($node['content'] ?? []);

        return match ($node['type'] ?? null) {
            'heading' => str_repeat('#', (int) ($node['attrs']['level'] ?? 2)).' '.$this->inline($content),
            'paragraph' => $this->inline($content),
            'bulletList' => $this->list($content, fn (int $i) => '- ', $indent),
            'orderedList' => $this->list($content, fn (int $i) => ($i + 1).'. ', $indent),
            'blockquote' => implode("\n", array_map(fn (string $line) => '> '.$line, explode("\n", $this->convert($content)))),
            'table' => $this->table($content),
            'horizontalRule' => '---',
            'set' => $this->set((array) ($node['attrs']['values'] ?? [])),
            default => '',
        };
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function list(array $items, callable $marker, int $indent): string
    {
        $lines = [];

        foreach (array_values($items) as $i => $item) {
            $parts = [];

            foreach ((array) ($item['content'] ?? []) as $child) {
                if (is_array($child)) {
                    $parts[] = $this->block($child, $indent + 1);
                }
            }

            $lines[] = str_repeat('  ', $indent).$marker($i).implode(' ', array_filter($parts));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function table(array $rows): string
    {
        $lines = [];

        foreach (array_values($rows) as $i => $row) {
            $cells = array_map(
                fn ($cell) => str_replace('|', '\\|', $this->convert((array) ($cell['content'] ?? []))),
                (array) ($row['content'] ?? []),
            );

            $lines[] = '| '.implode(' | ', $cells).' |';

            if ($i === 0) {
                $lines[] = '|'.str_repeat(' --- |', count($cells));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function set(array $values): string
    {
        if (! str_contains((string) ($values['type'] ?? ''), 'quote')) {
            return '';
        }

        $text = trim((string) ($values['text'] ?? $values['quote'] ?? ''));

        return $text === '' ? '' : '> '.str_replace("\n", "\n> ", $text);
    }

    /**
     * @param  array<int, mixed>  $nodes
     */
    private function inline(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'hardBreak') {
                $out .= "  \n";

                continue;
            }

            $text = (string) ($node['text'] ?? '');

            foreach ((array) ($node['marks'] ?? []) as $mark) {
                $text = match ($mark['type'] ?? null) {
                    'bold' => '**'.$text.'**',
                    'italic' => '*'.$text.'*',
                    'link' => '['.$text.']('.($mark['attrs']['href'] ?? '').')',
                    default => $text,
                };
            }

            $out .= $text;
        }

        return trim($out);
    }
}
