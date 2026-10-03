<?php
// php spikes/preview/render.php <case> [--no-markers] [--keep]
// Cases: spike-new (gw_spike collection, Bard sets + replicator), page-new
// (pages, structured), page-existing (About, supplements), journal-new.
// Builds the data with the addon's own apply mapping, marks a preview copy,
// tokenizes an UNSAVED entry with Statamic's Live Preview and fetches it.
// Nothing is saved: the script asserts the entry count and files are unchanged.

use Facades\Statamic\CP\LivePreview;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Drafts\HouseFinish;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Token;
use Statamic\Facades\URL;

require __DIR__.'/bootstrap.php';

$case = $argv[1] ?? 'spike-new';
$marked = ! in_array('--no-markers', $argv, true);
$keep = in_array('--keep', $argv, true);
$out = getenv('OUT') ?: sys_get_temp_dir();

$drafts = [
'spike-new' => <<<'Y'
title: A courtyard garden for a GP surgery in Morpeth
intro: Shade, year-round interest and somewhere to wait that isn't a corridor.
body: |-
  The courtyard sits between the waiting room and the car park, and gets about two hours of sun in June.

  ## What the practice asked for

  Benches for waiting patients, plants that look after themselves, and nothing that needs watering in August.

  > A garden people walk past on a bad day should still be kind to them.

  - Hellebores for winter flowers
  - Ferns along the north wall
  - A multi-stemmed birch for height
page_builder:
  - type: hero
    heading: Gardens for places people wait
    subheading: Surgeries, schools and care homes, designed to be looked at as much as used.
    button_text: Talk to us
  - type: text
    text: |
      ## Why waiting rooms

      Most people see a surgery's courtyard through a window. We design for that view first.
  - type: image
    caption: The courtyard in its first spring.
  - type: quote
    quote: The patients noticed before the staff did.
    attribution: Practice manager
  - type: cta
    heading: Have a courtyard nobody uses?
    text: Tell us about it and we'll come and look.
    link_text: Get in touch
Y,
'journal-new' => <<<'Y'
title: Rain gardens for small yards
excerpt: Where the downpipe goes is where the garden starts.
body: |-
  A rain garden is a shallow dip planted to take water off a roof.

  ## Where to put one

  At least three metres from the house, and downhill of it.

  > Water goes where it wants. A rain garden just agrees with it.
category: planting
Y,
];
$drafts['page-new'] = <<<'Y'
title: Garden visits (spike)
page_builder:
  - type: hero
    heading: A designer in your garden for a morning
    subheading: We walk the garden with you and leave you with a plan.
    button_text: Book a visit
  - type: text
    text: |
      ## How a visit works

      We spend about two hours walking the garden with you.
  - type: image
    caption: A border we planted after a visit in Hexham.
  - type: cta
    heading: Ready for a fresh pair of eyes?
    text: Tell us a little about your garden.
    link_text: Book a visit
Y;
$drafts['page-existing'] = $drafts['page-new'];

$collectionHandle = ['spike-new' => 'gw_spike', 'journal-new' => 'journal', 'page-new' => 'pages', 'page-existing' => 'pages'][$case];
$collection = Collection::find($collectionHandle);
$blueprint = $collection->entryBlueprint();
$reader = app(SchemaReader::class);
$layouts = app(EntryLayouts::class);
$specs = $reader->read($blueprint);
$schema = $reader->schema($blueprint);
$draft = Draft::parse($drafts[$case]);

$before = [Entry::query()->count(), trim(shell_exec('cd '.base_path().' && git status --porcelain content | md5'))];

// ---- the addon's apply mapping (SessionController::apply), minus the form merge
$t0 = microtime(true);
$existing = null;
if ($case === 'page-existing') {
    $existing = Entry::query()->where('collection', 'pages')->where('slug', 'about')->first();
    $built = $layouts->build($draft->data, $schema);
    $data = $built->data;
} elseif ($case === 'spike-new') {
    $data = $layouts->build($draft->data, $schema)->data;
} else {
    $pattern = $layouts->pattern($schema, $collectionHandle);
    $built = $layouts->build($draft->data, $schema, $pattern);
    $data = app(HouseFinish::class)->finish($built->data, $schema, $pattern, null, $draft->title())['data'];
}
$data = ['title' => $draft->title()] + $data;

// The panel's image slots (ImageStudio::place) and the arranger's inline sets, faked:
if ($case === 'spike-new') {
    foreach ($data['page_builder'] as $i => $set) {
        if ($set['type'] === 'image') $data['page_builder'][$i]['image'] = 'pages/materials.jpg';
        if ($set['type'] === 'hero') $data['page_builder'][$i]['image'] = 'pages/hero-evening.jpg';
    }
    $data['body'][] = ['type' => 'set', 'attrs' => ['id' => Str::random(8), 'values' => ['type' => 'photo', 'image' => 'journal/rain-garden.jpg']]];
    $data['body'][] = ['type' => 'set', 'attrs' => ['id' => Str::random(8), 'values' => ['type' => 'stats', 'items' => [['id' => 'r1', 'value' => '2 hours', 'label' => 'of sun in June'], ['id' => 'r2', 'value' => '14', 'label' => 'benches']]]]];
}
$mapMs = (microtime(true) - $t0) * 1000;
file_put_contents("$out/$case.data.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$markers = new SpikeMarkers;
$textFields = collect($specs)->filter(fn ($s) => in_array($s['type'], ['text', 'textarea'], true))->pluck('handle')->all();
$preview = $marked ? $markers->mark($data, $textFields,
    collect($specs)->where('type', 'replicator')->pluck('handle')->all(),
    collect($specs)->where('type', 'bard')->pluck('handle')->all()) : $data;

// ---- the unsaved entry
$t1 = microtime(true);
if ($existing) {
    $entry = $existing;                          // a fresh copy from the Stache, never saved
    foreach (\Illuminate\Support\Arr::except($preview, ['slug']) as $k => $v) $entry->setSupplement($k, $v);
} else {
    $entry = Entry::make()
        ->collection($collection)
        ->locale('default')
        ->slug(getenv('SLUG') ?: Str::slug($draft->title()))
        ->data($preview);
    if ($id = getenv('PREVIEW_ID')) $entry->id($id);
    if ($collection->dated()) $entry->date(now()->format('Y-m-d-His'));
}
$entry->setSupplement('live_preview', ['ghostwriter' => true]);
$token = LivePreview::tokenize(null, $entry)->token();
$targets = $entry->previewTargets();
$url = URL::makeAbsolute($targets[0]['url']);
$url .= (str_contains($url, '?') ? '&' : '?').(getenv('ASSUME') ? 'assume=1&' : '').'live-preview='.Str::random().'&token='.$token;
$tokenMs = (microtime(true) - $t1) * 1000;

$t2 = microtime(true);
$res = Http::withoutVerifying()->timeout(20)->get($url);
$fetchMs = (microtime(true) - $t2) * 1000;
$html = $res->body();
file_put_contents("$out/$case.html", $html);

preg_match_all(SpikeMarkers::PATTERN, $html, $m);
$decode = fn ($s) => implode('', array_map(fn ($c) => chr(mb_ord($c) - 0xE0000), mb_str_split($s)));
$found = array_unique(array_map(fn ($c) => explode('.', $decode($c))[0], $m[1]));
$expected = array_keys($markers->map);

if ($keep) { // for the browser test: the URL and the block map, deleted by the browser script
    file_put_contents("$out/$case.url", $url);
    file_put_contents("$out/$case.map.json", json_encode($markers->map, JSON_UNESCAPED_UNICODE));
} else {
    Token::find($token)?->delete();
    \Illuminate\Support\Facades\Cache::forget('statamic.live-preview.'.$token);
}
$after = [Entry::query()->count(), trim(shell_exec('cd '.base_path().' && git status --porcelain content | md5'))];

echo json_encode([
    'case' => $case, 'status' => $res->status(),
    'headers' => array_intersect_key(array_change_key_case($res->headers()), array_flip(['x-statamic-live-preview', 'x-frame-options', 'content-security-policy', 'x-statamic-draft'])),
    'url' => preg_replace('/token=\w+/', 'token=…', $url), 'id' => $entry->id(), 'uri' => $entry->uri(),
    'ms' => ['map' => round($mapMs), 'tokenize' => round($tokenMs), 'render_http' => round($fetchMs)],
    'markers' => ['expected' => count($expected), 'found' => count(array_intersect($expected, $found)), 'missing' => array_values(array_diff($expected, $found))],
    'map' => $markers->map,
    'saved_nothing' => $before === $after, 'entries' => [$before[0], $after[0]],
    'title' => preg_match('#<title>(.*?)</title>#s', $html, $tt) ? SpikeMarkers::strip($tt[1]) : null,
    'error' => $res->status() >= 400 ? Str::limit(strip_tags($html), 300) : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
