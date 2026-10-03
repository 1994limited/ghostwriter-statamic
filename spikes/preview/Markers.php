<?php
// Spike version of core's PreviewMarkers (§8.1): invisible tag-character
// codes prefixed to each text value of the *preview copy* of the data.
final class SpikeMarkers
{
    public const PATTERN = '/\x{E0067}\x{E0077}([\x{E0020}-\x{E007E}]{1,16})\x{E007F}/u';
    /** key => [path, label] */
    public array $map = [];

    public static function code(string $payload): string
    {
        $out = "\u{E0067}\u{E0077}";
        foreach (str_split($payload) as $c) {
            $out .= mb_chr(0xE0000 + ord($c), 'UTF-8');
        }
        return $out."\u{E007F}";
    }

    public static function strip(string $s): string
    {
        return preg_replace(self::PATTERN, '', $s);
    }

    /** Statamic storage-form data: replicator sets, Bard nodes, Bard sets, plain strings. */
    public function mark(array $data, array $textFields, array $builders, array $bards): array
    {
        foreach ($textFields as $h) {
            if (is_string($data[$h] ?? null) && $data[$h] !== '') {
                $data[$h] = self::code("f:$h").$data[$h];
                $this->map["f:$h"] = ['path' => $h, 'label' => $h];
            }
        }
        foreach ($bards as $h) {
            if (is_array($data[$h] ?? null)) {
                $data[$h] = $this->bard($data[$h], "f:$h", $h);
            }
        }
        foreach ($builders as $h) {
            foreach (($data[$h] ?? []) as $i => $set) {
                $key = 'b'.$i;
                $this->map[$key] = ['path' => "$h/$i", 'label' => $set['type'], 'assets' => $this->assets($set)];
                $n = 0;
                foreach ($set as $f => $v) {
                    if (in_array($f, ['id', 'type', 'enabled'], true)) continue;
                    if (is_string($v) && $v !== '' && ! $this->looksLikeRef($v)) {
                        $set[$f] = self::code("$key.$n").$v; $n++;
                    } elseif (is_array($v) && isset($v[0]['type'])) { // Bard inside a set
                        $set[$f] = $this->bard($v, "$key.$n", "$h/$i/$f", $key); $n++;
                    }
                }
                $data[$h][$i] = $set;
            }
        }
        return $data;
    }

    private function bard(array $nodes, string $fieldKey, string $path, ?string $parent = null): array
    {
        // Every top-level node gets a unit key (s0, s1…); a set's text values are marked as a child block.
        foreach ($nodes as $i => $node) {
            $key = ($parent ?? $fieldKey).'s'.$i;
            if (($node['type'] ?? null) === 'set') {
                $vals = $node['attrs']['values'];
                $this->map[$key] = ['path' => "$path/$i", 'label' => 'set:'.$vals['type'], 'parent' => $parent ?? $fieldKey, 'assets' => $this->assets($vals)];
                $n = 0;
                foreach ($vals as $f => $v) {
                    if ($f === 'type') continue;
                    if (is_string($v) && $v !== '' && ! $this->looksLikeRef($v)) { $vals[$f] = self::code("$key.$n").$v; $n++; }
                    if (is_array($v) && isset($v[0]) && is_array($v[0]) && ! isset($v[0]['type'])) { // grid rows
                        foreach ($v as $r => $row) foreach ($row as $rf => $rv) if (is_string($rv) && $rf !== 'id') { $vals[$f][$r][$rf] = self::code("$key.$n").$rv; $n++; }
                    }
                }
                $nodes[$i]['attrs']['values'] = $vals;
                continue;
            }
            if ($this->firstText($nodes[$i], self::code($key))) {
                $this->map[$key] = ['path' => "$path/$i", 'label' => $node['type'], 'parent' => $parent ?? $fieldKey];
            }
        }
        return $nodes;
    }

    private function firstText(array &$node, string $code): bool
    {
        if (($node['type'] ?? null) === 'text' && isset($node['text'])) { $node['text'] = $code.$node['text']; return true; }
        foreach (($node['content'] ?? []) as $k => $child) {
            if ($this->firstText($node['content'][$k], $code)) return true;
        }
        return false;
    }

    private function looksLikeRef(string $v): bool
    {
        return (bool) preg_match('#^(entry::|asset::|https?://|/|\#|[\w-]+/[\w./-]+\.(jpe?g|png|webp|gif|svg)$)#i', $v);
    }

    private function assets(array $set): array
    {
        $out = [];
        array_walk_recursive($set, function ($v) use (&$out) {
            if (is_string($v) && preg_match('#\.(jpe?g|png|webp|gif|svg)$#i', $v)) $out[] = basename($v);
        });
        return $out;
    }
}
