<?php
// php spikes/preview/inprocess.php
// Fixes for unsaved entries that the HTTP render can't show without addon
// code in the site process, so they run in-process through the HTTP kernel:
//  A. a structured collection (pages): an unsaved entry has no uri, so the
//     preview URL is "/" and Home renders. PreviewEntry gives it one.
//  B. {{ collection:next }} etc. call Entry::find(id), which ignores Live
//     Preview substitutions: a repository whose find() checks them first.
use Facades\Statamic\CP\LivePreview;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Token;
use Statamic\Statamic;

require __DIR__.'/bootstrap.php';

class SpikePreviewEntry extends \Statamic\Entries\Entry
{
    public ?string $previewUri = null;
    public function uri() { return $this->previewUri ?? parent::uri(); }
}
class SpikePreviewEntryRepository extends \Statamic\Stache\Repositories\EntryRepository
{
    public function find($id): ?\Statamic\Contracts\Entries\Entry
    {
        return $this->substitutionsById[(string) $id] ?? parent::find($id);
    }
}

// Laravel 13 restricts what the cache may unserialize; Statamic and addons
// add to the list (AddonServiceProvider::registerSerializableClasses()).
config(['cache.serializable_classes' => array_merge(config('cache.serializable_classes'), [SpikePreviewEntry::class])]);
\Illuminate\Support\Facades\Cache::forgetDriver(config('cache.default'));
$fixes = in_array('--fix', $argv, true);
if ($fixes) {
    Statamic::repository(\Statamic\Contracts\Entries\EntryRepository::class, SpikePreviewEntryRepository::class);
    EntryFacade::clearResolvedInstances();
}

function render(\Statamic\Entries\Entry $entry, string $query = ''): array {
    $entry->setSupplement('live_preview', ['ghostwriter' => true]);
    $token = LivePreview::tokenize(null, $entry)->token();
    $uri = $entry->uri() ?? '/';
    $req = Request::create('https://gw-test-statamic.test'.$uri.'?'.$query.'live-preview=x&token='.$token);
    $t = microtime(true);
    $res = app(\Illuminate\Contracts\Http\Kernel::class)->handle($req);
    $ms = round((microtime(true) - $t) * 1000);
    Token::find($token)?->delete();
    $html = $res->getContent();
    preg_match('#<title>(.*?)</title>#s', $html, $tt);
    return ['uri' => $uri, 'status' => $res->getStatusCode(), 'ms' => $ms, 'title' => SpikeMarkers::strip($tt[1] ?? ''),
        'exception' => $res->exception ? get_class($res->exception).': '.Str::limit($res->exception->getMessage(), 120).' @ '.str_replace(base_path().'/', '', $res->exception->getFile()).':'.$res->exception->getLine() : null,
        'next_rendered' => str_contains($html, '<p>next:'), 'aside' => preg_match('~<aside id="assumptions">(.*?)</aside>~s', $html, $a) ? Str::limit(trim(preg_replace('~\s+~', ' ', strip_tags($a[1]))), 300) : null];
}

$out = [];
// A. pages, unsaved, with and without the preview uri
$make = function (bool $withUri) {
    $e = (new SpikePreviewEntry)->collection(Collection::find('pages'))->locale('default')->slug('garden-visits-spike')
        ->id('gw-preview-'.Str::lower(Str::random(10)))
        ->data(['title' => 'Garden visits (spike)', 'page_builder' => [['id' => 'x1', 'type' => 'quote', 'enabled' => true, 'quote' => 'Spike quote text']]]);
    if ($withUri) $e->previewUri = '/garden-visits-spike';   // parent uri + slug, as the form's parent field would give
    return $e;
};
$out['pages_no_uri'] = render($make(false));
$out['pages_preview_uri'] = render($make(true));
// B. gw_spike with next/previous in the template
// a dated collection (journal), rendered with the spike template so next/previous run
$e = (new SpikePreviewEntry)->collection(Collection::find('journal'))->locale('default')->slug('np-spike')
    ->id('gw-preview-'.Str::lower(Str::random(10)))->date(now()->subYears(5)->format('Y-m-d-His'))
    ->data(['title' => 'Next/prev spike', 'intro' => 'np', 'template' => 'gw_spike/show']);
$out['next_previous'] = render($e, 'assume=1&');
echo json_encode(['fixes' => $fixes] + $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
