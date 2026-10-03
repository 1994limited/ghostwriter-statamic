<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Facades\Statamic\CP\LivePreview;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewMarkers;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftValues;
use NineteenNinetyFour\Ghostwriter\Images\ContainerAssetSink;
use NineteenNinetyFour\Ghostwriter\Preview\BardSetMarkers;
use NineteenNinetyFour\Ghostwriter\Preview\PreviewEntry;
use NineteenNinetyFour\Ghostwriter\Preview\PreviewEntryRepository;
use NineteenNinetyFour\Ghostwriter\Preview\PreviewPolicy;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\EntryRepository;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Token;
use Statamic\Fields\Value;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Preview tab (design §7.2): the unsaved draft rendered through the
 * site's own templates with Live Preview, exactly as apply would fill the
 * form, with markers in the preview's copy only, and nothing saved.
 */
final class PagePreviewTest extends TestCase
{
    private const DRAFT = "title: Rain Gardens for Small Yards\nintro: Where the downpipe goes is where the garden starts.\nbody: |-\n  A rain garden is a shallow dip.\n\n  ## Where to put one\n\n  Downhill of the house.";

    private string $views;

    protected function setUp(): void
    {
        parent::setUp();

        // The site's templates, as a site would have them.
        $this->views = $this->workspace.'/views';
        File::ensureDirectoryExists($this->views.'/journal');
        File::put($this->views.'/layout.antlers.html', '<!doctype html><html><head><title>{{ title }} · Site</title></head><body><header><nav><a href="/">Home</a></nav></header><main>{{ template_content }}</main></body></html>');
        File::put($this->views.'/journal/show.antlers.html', implode("\n", [
            '{{ if live_preview:ghostwriter }}<p id="flag">ghostwriter</p>{{ /if }}',
            '{{ if intro | contains:explode }}{{ collection:no_such_collection }}{{ /collection:no_such_collection }}{{ /if }}',
            '<article><h1>{{ title }}</h1><p class="intro">{{ intro }}</p>{{ body }}',
            '<nav class="pager">{{ collection:previous in="journal" as="p" }}{{ p }}<a href="{{ url }}">{{ title }}</a>{{ /p }}{{ /collection:previous }}{{ collection:next in="journal" as="n" }}{{ n }}<a href="{{ url }}">{{ title }}</a>{{ /n }}{{ /collection:next }}</nav></article>',
        ]));
        View::addLocation($this->views);

        Collection::make('journal')->title('Journal')->routes('/journal/{slug}')->dated(true)->template('journal/show')->layout('layout')->save();
        Blueprint::make('post')->setNamespace('collections.journal')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'intro', 'field' => ['type' => 'textarea']],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'buttons' => ['h2', 'bold', 'italic', 'unorderedlist', 'link']]],
        ]])->save();

        Entry::make()->collection('journal')->slug('older')->date('2025-01-01')->published(true)->data(['title' => 'An Older Post', 'intro' => 'Before.'])->save();

        app(TypeRepository::class)->save(TypeRepository::make('journal', ['title' => 'Journal post', 'collection' => 'journal', 'questions' => []]));
    }

    public function test_the_draft_is_rendered_through_the_sites_template_and_nothing_is_saved(): void
    {
        $this->signInWith(['access ghostwriter', 'view journal entries', 'create journal entries']);
        $session = $this->sessionWithDraft(self::DRAFT);
        $entries = Entry::query()->count();

        $preview = $this->postJson(cp_route('ghostwriter.sessions.preview', $session->id), ['values' => ['slug' => '']])->assertOk()->json();

        $this->assertTrue($preview['available']);
        $this->assertTrue($preview['same_origin']);
        $this->assertFalse($preview['cached']);
        $this->assertStringContainsString('/journal/rain-gardens-for-small-yards?live-preview=', $preview['url']);
        $this->assertSame('f1', $preview['title_key']);
        $this->assertSame(['f1', 'f2', 'f3', 's1', 's2'], array_column($preview['map'], 'key'));
        $this->assertSame(['f3', 'f3'], array_column(array_slice($preview['map'], 3), 'parent'));

        $response = $this->get($this->path($preview['url']));
        $response->assertOk();
        $html = (string) $response->getContent();

        // The site's own template, with the preview flag, the draft's words and their markers.
        $this->assertStringContainsString('<p id="flag">ghostwriter</p>', $html);
        $this->assertSame(['f1', 'f1', 'f2', 's1', 's2', 'f3'], array_column(PreviewMarkers::decode($html), 'payload'));
        $page = PreviewMarkers::stripText($html);
        $this->assertStringContainsString('<title>Rain Gardens for Small Yards · Site</title>', $page);
        $this->assertStringContainsString('<h2>Where to put one</h2>', $page);
        // collection:next and previous load the current entry by id: it works for one never saved.
        $this->assertStringContainsString('<a href="/journal/older">An Older Post</a>', $page);

        // The policy headers.
        $this->assertSame((new PreviewPolicy)->contentSecurityPolicy(), $response->headers->get('Content-Security-Policy'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertSame('1', $response->headers->get('X-Ghostwriter-Preview'));

        // Nothing saved, and the session isn't marked as used.
        $this->assertSame($entries, Entry::query()->count());
        $this->assertNull($this->sessions()->find($session->id)->appliedAt);
        $this->assertSame([], Entry::query()->where('slug', 'rain-gardens-for-small-yards')->get()->all());
    }

    public function test_the_preview_renders_exactly_what_apply_would_set(): void
    {
        $this->signInWith(['access ghostwriter', 'view journal entries', 'create journal entries']);
        $session = $this->sessionWithDraft(self::DRAFT);

        $preview = $this->postJson(cp_route('ghostwriter.sessions.preview', $session->id))->assertOk()->json();
        $item = LivePreview::item(Token::find($this->token($preview['url'])));

        $this->assertInstanceOf(PreviewEntry::class, $item);
        $this->assertTrue(PreviewEntry::isPreviewId($item->id()));

        // Apply's own data, built as apply builds it.
        $type = app(TypeRepository::class)->find('journal');
        $blueprint = Collection::findByHandle('journal')->entryBlueprint();
        $applied = app(DraftValues::class)->build($session, $type, Draft::parse(self::DRAFT), $blueprint, [], null)->data;

        $this->assertTrue(PreviewMarkers::contains($item->data()->all()));
        $this->assertFalse(PreviewMarkers::contains($applied));
        $this->assertSame($applied, PreviewMarkers::strip($item->data()->all()));

        // Apply itself still puts no marker in the form.
        $this->assertFalse(PreviewMarkers::contains($this->postJson(cp_route('ghostwriter.sessions.apply', $session->id))->assertOk()->json()));
    }

    public function test_the_same_draft_reuses_its_render_and_a_changed_one_replaces_its_token(): void
    {
        $this->signInWith(['access ghostwriter', 'view journal entries', 'create journal entries']);
        $session = $this->sessionWithDraft(self::DRAFT);

        $first = $this->postJson(cp_route('ghostwriter.sessions.preview', $session->id))->json();
        $again = $this->postJson(cp_route('ghostwriter.sessions.preview', $session->id))->json();

        $this->assertTrue($again['cached']);
        $this->assertSame($first['url'], $again['url']);

        // Four more versions of the draft: only the last few renders keep a token.
        $tokens = [$this->token($first['url'])];

        foreach (range(1, 4) as $n) {
            $changed = $this->sessions()->find($session->id);
            $changed->draft = str_replace('Small Yards', 'Small Yards '.$n, self::DRAFT);
            $this->sessions()->save($changed);

            $render = $this->postJson(cp_route('ghostwriter.sessions.preview', $session->id))->json();
            $this->assertFalse($render['cached']);
            $tokens[] = $this->token($render['url']);
        }

        $this->assertNull(Token::find($tokens[0]), 'The oldest render kept its token.');
        $this->assertNull(Cache::get('statamic.live-preview.'.$tokens[0]));
        $this->assertNotNull(Token::find($tokens[4]));

        // Deleting the piece deletes its renders' tokens.
        $this->deleteJson(cp_route('ghostwriter.sessions.destroy', $session->id))->assertOk();
        $this->assertNull(Token::find($tokens[4]));
    }

    public function test_a_template_that_fails_shows_a_page_the_panel_can_read(): void
    {
        $this->signInWith(['access ghostwriter', 'view journal entries', 'create journal entries']);
        $session = $this->sessionWithDraft("title: Broken\nintro: explode");

        $preview = $this->postJson(cp_route('ghostwriter.sessions.preview', $session->id))->assertOk()->json();
        $response = $this->get($this->path($preview['url']));

        $response->assertStatus(500);
        $this->assertMatchesRegularExpression('/<meta name="ghostwriter-preview-error" content="[^"]*Collection \[no_such_collection\] not found/', (string) $response->getContent());
        // Not a super user: no exception class or file.
        $this->assertStringNotContainsString('CollectionNotFoundException', (string) $response->getContent());
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function test_statamics_own_live_preview_is_left_alone(): void
    {
        $this->signInWith(['access ghostwriter', 'view journal entries', 'create journal entries']);

        $entry = Entry::query()->where('slug', 'older')->first();
        $token = LivePreview::tokenize(null, $entry);

        $response = $this->get('/journal/older?live-preview=x&token='.$token->token())->assertOk();

        $this->assertNull($response->headers->get('X-Ghostwriter-Preview'));
        $this->assertStringNotContainsString('id="flag"', (string) $response->getContent());
        $this->assertSame(app(EntryRepository::class), Entry::getFacadeRoot());
        $this->assertNotInstanceOf(PreviewEntryRepository::class, app(EntryRepository::class));
    }

    public function test_the_policy_is_added_beside_one_the_response_already_has(): void
    {
        config(['ghostwriter.preview.script_hosts' => 'https://cdn.example.com, bad; host']);

        $response = PreviewPolicy::fromConfig()->apply(new Response('', 200, ['Content-Security-Policy' => 'frame-ancestors https://a.example https://b.example']));

        $this->assertSame([
            'frame-ancestors https://a.example https://b.example',
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.example.com; connect-src 'self'; form-action 'none'; frame-ancestors 'self'; base-uri 'self'",
        ], $response->headers->all('content-security-policy'));
    }

    public function test_the_repository_answers_the_previewed_entry_by_id_and_delegates_the_rest(): void
    {
        $entry = (new PreviewEntry)->id('gw-preview-abc')->collection('journal')->slug('x');
        $repository = new PreviewEntryRepository(app(EntryRepository::class), $entry);

        $this->assertSame($entry, $repository->find('gw-preview-abc'));
        $this->assertSame($entry, $repository->find(new Value('gw-preview-abc')));
        $this->assertSame('An Older Post', $repository->find(Entry::query()->where('slug', 'older')->first()->id())->get('title'));
        $this->assertNull($repository->find('nothing'));
        $this->assertSame(1, $repository->query()->where('collection', 'journal')->count());
    }

    public function test_a_new_page_in_a_structured_collection_gets_the_uri_it_will_have(): void
    {
        Collection::make('pages')->title('Pages')->routes('{parent_uri}/{slug}')->structureContents(['root' => true])->save();
        $home = tap(Entry::make()->collection('pages')->slug('home')->data(['title' => 'Home']))->save();
        $services = tap(Entry::make()->collection('pages')->slug('services')->data(['title' => 'Services']))->save();
        Collection::findByHandle('pages')->structure()->in('default')->tree([['entry' => $home->id()], ['entry' => $services->id()]])->save();

        $make = fn (?string $parent) => (new PreviewEntry)->id(PreviewEntry::newId())->collection('pages')->locale('default')->slug('garden-visits')->previewParent($parent);

        $this->assertSame('/garden-visits', $make(null)->uri());
        $this->assertSame('/garden-visits', $make($home->id())->uri());
        $this->assertSame('/services/garden-visits', $make($services->id())->uri());
    }

    public function test_only_someone_who_could_save_the_entry_sees_it_and_a_collection_without_pages_has_no_preview(): void
    {
        $this->signInWith(['access ghostwriter', 'view journal entries', 'create journal entries']);
        $session = $this->sessionWithDraft(self::DRAFT);

        $this->assertSame(['timeout' => 8], $this->getJson(cp_route('ghostwriter.sessions.show', $session->id))->json('page_preview'));

        Collection::findByHandle('journal')->routes(null)->save();
        $this->assertFalse($this->postJson(cp_route('ghostwriter.sessions.preview', $session->id))->assertOk()->json('available'));
        $this->assertNull($this->getJson(cp_route('ghostwriter.sessions.show', $session->id))->json('page_preview'));

        config(['ghostwriter.preview.enabled' => false]);
        $this->postJson(cp_route('ghostwriter.sessions.preview', $session->id))->assertNotFound();

        config(['ghostwriter.preview.enabled' => true]);
        $this->setTestRoles(['tester' => ['access cp', 'access ghostwriter', 'view journal entries']]);
        $this->postJson(cp_route('ghostwriter.sessions.preview', $session->id))->assertForbidden();
    }

    public function test_an_entry_being_edited_is_previewed_with_the_draft_over_it_and_its_file_unchanged(): void
    {
        $this->signInWith(['access ghostwriter', 'view journal entries', 'edit journal entries']);
        $entry = Entry::query()->where('slug', 'older')->first();
        $file = (string) file_get_contents($entry->path());

        $session = $this->sessionWithDraft("title: An Older Post, Rewritten\nintro: After.");
        $session->source = $entry->id();
        $session->editing = true;
        $this->sessions()->save($session);

        $preview = $this->postJson(cp_route('ghostwriter.sessions.preview', $session->id), ['values' => ['title' => 'An Older Post', 'intro' => 'Before.']])->assertOk()->json();

        $this->assertStringContainsString('/journal/older?live-preview=', $preview['url']);
        $page = PreviewMarkers::stripText((string) $this->get($this->path($preview['url']))->assertOk()->getContent());

        $this->assertStringContainsString('<h1>An Older Post, Rewritten</h1>', $page);
        $this->assertStringContainsString('<p class="intro">After.</p>', $page);
        $this->assertSame($file, file_get_contents($entry->path()));
        $this->assertSame('An Older Post', Entry::find($entry->id())->get('title'));
    }

    public function test_the_preview_uses_a_placeholder_image_already_there_and_never_makes_one(): void
    {
        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();
        $field = new Field('image', Kind::Reference, 'Image', meta: ['container' => 'assets', 'max_files' => 1]);

        $this->assertNull((new ContainerAssetSink(create: false))->placeholder($field, fn () => 'png'));
        Storage::disk('assets')->assertMissing(ContainerAssetSink::PATH);

        $this->assertSame(ContainerAssetSink::PATH, (new ContainerAssetSink)->placeholder($field, fn () => 'png'));
        $this->assertSame(ContainerAssetSink::PATH, (new ContainerAssetSink(create: false))->placeholder($field, fn () => 'png'));
    }

    public function test_bard_sets_are_blocks_of_their_own_inside_their_section(): void
    {
        Blueprint::make('story')->setNamespace('collections.journal')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'buttons' => ['h2'], 'sets' => ['main' => ['sets' => [
                'photo' => ['display' => 'Photo', 'fields' => [['handle' => 'image', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1]]]],
                'stats' => ['display' => 'Stats', 'fields' => [['handle' => 'items', 'field' => ['type' => 'grid', 'fields' => [['handle' => 'value', 'field' => ['type' => 'text']], ['handle' => 'label', 'field' => ['type' => 'text']]]]]]],
            ]]]]],
        ]])->save();
        $schema = app(SchemaReader::class)->schema(Blueprint::find('collections.journal.story'));
        $text = fn (string $words) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $words]]];

        $data = ['title' => 'Sets', 'body' => [
            $text('A lead paragraph before any heading.'),
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'The site']]],
            $text('Two hours of sun in June.'),
            ['type' => 'set', 'attrs' => ['id' => 'p1', 'values' => ['type' => 'photo', 'image' => 'journal/rain-garden.jpg']]],
            ['type' => 'set', 'attrs' => ['id' => 's1', 'values' => ['type' => 'stats', 'items' => [['id' => 'r1', 'value' => '14', 'label' => 'benches']]]]],
            $text('Hellebores for winter.'),
        ]];

        $marked = (new BardSetMarkers)->mark((new PreviewMarkers)->mark($data, $schema), $schema);
        $map = collect($marked->map->toArray())->keyBy('key');

        // After core's keys; inside the section they sit in.
        $this->assertSame(['f1', 'f2', 's1', 's2', 'b1', 'b2'], $map->keys()->all());
        $this->assertSame(['Photo', 's2', ['rain-garden.jpg']], [$map['b1']['label'], $map['b1']['parent'], $map['b1']['assets']]);
        $this->assertSame(['Stats', 's2'], [$map['b2']['label'], $map['b2']['parent']]);

        // The set's text carries its marker; without markers it is the data as it was, and the hash is unchanged.
        $this->assertSame(['b2.0', 'b2.0'], array_column(PreviewMarkers::decode(json_encode($marked->data['body'][4], JSON_UNESCAPED_UNICODE)), 'payload'));
        $this->assertSame($data, PreviewMarkers::strip($marked->data));
        $this->assertSame((new PreviewMarkers)->mark($data, $schema)->hash, $marked->hash);
    }

    private function sessionWithDraft(string $draft): Session
    {
        $session = $this->makeSession('journal');
        $session->addMessage('user', 'A brief.');
        $session->answer('Here it is.', $draft);

        return $this->sessions()->save($session);
    }

    private function path(string $url): string
    {
        $parts = parse_url($url);

        return $parts['path'].'?'.$parts['query'];
    }

    private function token(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['token'];
    }
}
