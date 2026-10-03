<?php
// php spikes/preview/modifiers.php — Antlers modifiers on a marked value, marker first (prefix) and last (suffix).
require __DIR__.'/bootstrap.php';
use Statamic\Facades\Antlers;
$m = SpikeMarkers::code('b7.2');
$text = 'Shade, year-round interest and somewhere to wait.';
$out = [];
foreach (['upper', 'title', 'ucfirst', 'truncate:30', 'safe_truncate:30', 'widont', 'smartypants', 'slugify', 'markdown', 'strip_tags', 'sentence_list', 'word_count', 'length', 'entities'] as $mod) {
    foreach (['prefix' => $m.$text, 'suffix' => $text.$m] as $where => $v) {
        try { $r = (string) Antlers::parse('{{ v | '.$mod.' }}', ['v' => $v]); } catch (Throwable $e) { $r = 'ERR '.$e->getMessage(); }
        $d = html_entity_decode($r);
        $out[$mod][$where] = (preg_match(SpikeMarkers::PATTERN, $d) ? 'kept  ' : 'lost  ').mb_substr(SpikeMarkers::strip($d), 0, 38);
    }
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
