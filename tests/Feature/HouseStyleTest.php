<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseRules;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Images\ContainerAssetSink;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * What the model entries agree on place by place, and how it reaches a new
 * entry: settings by position, nested items, links (to the page itself, or
 * to example.com for now), the dressing on rich text, and striped
 * placeholders where an image is still to come.
 */
class HouseStyleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->workspace.'/assets', 'url' => '/assets']]);
        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();

        Collection::make('pages')->title('Pages')->save();

        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'featured_image', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => 'Featured Image']],
            ['handle' => 'brochure', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'validate' => ['mimes:pdf']]],
            ['handle' => 'page_builder', 'field' => ['type' => 'replicator', 'sets' => [
                'spacer' => ['display' => 'Spacer', 'fields' => [['handle' => 'height', 'field' => ['type' => 'text']]]],
                'hero' => ['display' => 'Hero', 'fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'bard']],
                    ['handle' => 'image', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => 'Image']],
                    ['handle' => 'background', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => 'Background']],
                    ['handle' => 'button_text', 'field' => ['type' => 'text']],
                    ['handle' => 'button_link', 'field' => ['type' => 'link']],
                ]],
                'breadcrumbs' => ['display' => 'Breadcrumbs', 'fields' => [
                    ['handle' => 'crumbs', 'field' => ['type' => 'replicator', 'sets' => [
                        'crumb' => ['display' => 'Crumb', 'fields' => [
                            ['handle' => 'label', 'field' => ['type' => 'text']],
                            ['handle' => 'link', 'field' => ['type' => 'link']],
                        ]],
                    ]]],
                ]],
            ]]],
        ]])->save();
    }

    /**
     * A page as the site builds them: a spacer, a hero with a centred bold
     * heading and a button to the contact page, three breadcrumbs (Home,
     * Studio, itself), and a closing spacer.
     *
     * @return array<string, mixed>
     */
    private function page(string $id, string $title, array $overrides = []): array
    {
        $heading = fn (string $text) => [['type' => 'heading', 'attrs' => ['level' => 1, 'textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => $text, 'marks' => [['type' => 'bold']]]]]];

        return array_replace_recursive([
            'title' => $title,
            'featured_image' => "pages/{$id}.png",
            'page_builder' => [
                ['id' => 's1', 'type' => 'spacer', 'enabled' => true, 'height' => '45/65'],
                ['id' => 'h1', 'type' => 'hero', 'enabled' => true, 'heading' => $heading($title), 'image' => "heroes/{$id}.png", 'button_text' => 'Talk to us', 'button_link' => 'entry::contact'],
                ['id' => 'b1', 'type' => 'breadcrumbs', 'enabled' => true, 'crumbs' => [
                    ['id' => 'c1', 'type' => 'crumb', 'enabled' => true, 'label' => 'Home', 'link' => 'entry::home'],
                    ['id' => 'c2', 'type' => 'crumb', 'enabled' => true, 'label' => 'Studio', 'link' => 'entry::studio'],
                    ['id' => 'c3', 'type' => 'crumb', 'enabled' => true, 'label' => $title, 'link' => "entry::{$id}"],
                ]],
                ['id' => 's2', 'type' => 'spacer', 'enabled' => true, 'height' => '60/100'],
            ],
        ], $overrides);
    }

    private function schema(): Schema
    {
        return app(SchemaReader::class)->schema(Collection::findByHandle('pages')->entryBlueprint());
    }

    /**
     * What the pages agree on, each keyed by its entry ID.
     *
     * @param  array<string, array<string, mixed>>  $pages
     */
    private function learn(array $pages): HouseRules
    {
        return app(Layouts::class)->houseStyle()->learn(array_map(fn (string $id) => new EntryData($pages[$id], $id), array_keys($pages)), $this->schema());
    }

    public function test_settings_links_sequences_and_markup_agreed_by_position_go_into_a_new_page(): void
    {
        $pages = ['p1' => $this->page('p1', 'Yacht Studio'), 'p2' => $this->page('p2', 'Architecture Studio'), 'p3' => $this->page('p3', 'Visualisation Studio')];
        $pages['p3']['page_builder'][3]['height'] = '80/120';

        $style = $this->learn($pages);

        // The first spacer is agreed; the last is two to one, which is enough for a setting.
        $this->assertSame('45/65', $style->positions['page_builder/spacer#0']['height']);
        $this->assertSame('60/100', $style->positions['page_builder/spacer#1']['height']);

        // Every page's hero links to the contact page; the last crumb to itself.
        $this->assertSame('entry::contact', $style->positions['page_builder/hero#0']['button_link']);
        $this->assertSame(LinkDialect::SELF, $style->positions['page_builder/breadcrumbs#0/crumbs/crumb#2']['link']);
        $this->assertSame(LinkDialect::TITLE, $style->positions['page_builder/breadcrumbs#0/crumbs/crumb#2']['label']);
        $this->assertSame(['crumb', 'crumb', 'crumb'], $style->sequences['page_builder/breadcrumbs#0/crumbs']);
        $this->assertSame(['textAlign' => 'center'], $style->markup['page_builder/hero.heading']['heading1']['attrs']);
        $this->assertSame([['type' => 'bold']], $style->markup['page_builder/hero.heading']['heading1']['marks']);

        $house = app(EntryLayouts::class)->apply([
            'title' => 'Studio Winch',
            'page_builder' => [
                ['type' => 'spacer'],
                ['type' => 'hero', 'heading' => [['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Winches, rigged right']]]], 'button_text' => 'Talk to us'],
                ['type' => 'breadcrumbs'],
                ['type' => 'spacer'],
            ],
        ], $this->schema(), $style, null, 'Studio Winch');

        $data = $house->data;
        $blocks = $data['page_builder'];

        $this->assertSame('45/65', $blocks[0]['height']);
        $this->assertSame('60/100', $blocks[3]['height']);
        $this->assertSame('entry::contact', $blocks[1]['button_link']);

        // The writer's plain heading, dressed as the house does.
        $this->assertSame(['level' => 1, 'textAlign' => 'center'], $blocks[1]['heading'][0]['attrs']);
        $this->assertSame([['type' => 'bold']], $blocks[1]['heading'][0]['content'][0]['marks']);
        $this->assertSame('Winches, rigged right', $blocks[1]['heading'][0]['content'][0]['text']);

        // Three crumbs made; the last waits for the page to exist, then links to it.
        $crumbs = $blocks[2]['crumbs'];
        $this->assertSame(['Home', 'Studio', 'Studio Winch'], array_column($crumbs, 'label'));
        $this->assertSame(['entry::home', 'entry::studio'], [$crumbs[0]['link'], $crumbs[1]['link']]);
        $this->assertArrayNotHasKey('link', $crumbs[2]);

        $linked = app(EntryLayouts::class)->linkToSelf($data, $this->schema(), $style, 'new-id', 'Studio Winch');
        $this->assertSame('entry::new-id', $linked['page_builder'][2]['crumbs'][2]['link']);
        $this->assertSame([], $house->toFill);
    }

    public function test_a_link_to_the_page_itself_is_found_whatever_each_page_calls_it_and_unopposed_links_count(): void
    {
        $pages = ['p1' => $this->page('p1', 'Yacht Studio'), 'p2' => $this->page('p2', 'Architecture Studio'), 'p3' => $this->page('p3', 'Visualisation Studio'), 'p4' => $this->page('p4', 'Procurement')];
        $pages['p2']['page_builder'][2]['crumbs'][2]['label'] = 'Architecture';
        unset($pages['p3']['page_builder'][2]['crumbs'][2]['link'], $pages['p4']['page_builder'][2]['crumbs'][2]['link']);

        // Two of four link the last crumb to themselves, two leave it empty, none elsewhere.
        $style = $this->learn($pages);
        $this->assertSame(LinkDialect::SELF, $style->positions['page_builder/breadcrumbs#0/crumbs/crumb#2']['link']);

        // One page's hero links somewhere else: no longer agreed, so nothing is copied.
        $pages['p4']['page_builder'][1]['button_link'] = 'entry::pricing';
        $style = $this->learn($pages);
        $this->assertArrayNotHasKey('button_link', $style->positions['page_builder/hero#0']);

        // The hero still usually has a link, so a new page gets a link to
        // choose (Finish this page finds it) and says so.
        $house = app(EntryLayouts::class)->apply(['title' => 'New', 'page_builder' => [['type' => 'hero']]], $this->schema(), $style, null, '');
        $data = $house->data;

        $this->assertSame('#gw-link:button-link', $data['page_builder'][0]['button_link']);
        // The button's words are agreed, so they stay; only the link is stood in for.
        $this->assertSame('Talk to us', $data['page_builder'][0]['button_text']);
        $this->assertSame(['Hero (link still to choose)'], $house->toFill);
    }

    public function test_striped_placeholders_go_where_an_image_belongs_and_nowhere_else(): void
    {
        $rates = ['featured_image' => 1.0, 'brochure' => 1.0, 'hero.image' => 1.0, 'hero.background' => 0.25];
        $placeholders = new Placeholders(new ContainerAssetSink, $rates);

        $data = $placeholders->fill(['title' => 'New', 'page_builder' => [['type' => 'hero', 'heading' => []]]], $this->schema());

        $this->assertSame(ContainerAssetSink::PATH, $data['featured_image']);
        $this->assertSame(ContainerAssetSink::PATH, $data['page_builder'][0]['image']);
        // Optional background: left alone. Brochure takes PDFs: no picture.
        $this->assertArrayNotHasKey('background', $data['page_builder'][0]);
        $this->assertArrayNotHasKey('brochure', $data);
        $this->assertSame(['Featured Image', 'Hero: Image'], $placeholders->filled());

        // One shared file, drawn once.
        Storage::disk('assets')->assertExists(ContainerAssetSink::PATH);
        $this->assertSame('Image to choose (placeholder from Ghostwriter)', AssetContainer::find('assets')->asset(ContainerAssetSink::PATH)->get('title'));
        [$width, $height] = getimagesizefromstring(Storage::disk('assets')->get(ContainerAssetSink::PATH));
        $this->assertSame([1600, 1000], [$width, $height]);
    }

    public function test_a_draft_put_into_a_new_entry_gets_the_house_style_and_placeholders_but_an_edited_entry_does_not(): void
    {
        $this->signIn();
        // The pages link to entries this test doesn't make; the publish
        // guard would refuse them as broken links.
        config(['ghostwriter.publish.on_unfinished' => 'warn']);

        foreach (['p1' => 'Yacht Studio', 'p2' => 'Architecture Studio', 'p3' => 'Visualisation Studio'] as $slug => $title) {
            $entry = Entry::make()->collection('pages')->slug($slug)->published(true)->data($this->page($slug, $title));
            $entry->save();
            // The self link must point at the real ID, which is only known once saved.
            $data = $entry->data()->all();
            $data['page_builder'][2]['crumbs'][2]['link'] = 'entry::'.$entry->id();
            $entry->data($data)->save();
        }

        $pattern = app(EntryLayouts::class)->pattern($this->schema(), 'pages');
        $this->assertSame('45/65', $pattern->house->positions['page_builder/spacer#0']['height']);
        $this->assertSame(1.0, $pattern->filled['hero.image']);

        $session = $this->makeSession('any:pages', ['subject' => 'Winches']);
        $session->draft = "title: Studio Winch\npage_builder:\n  - type: spacer\n  - type: hero\n    heading: |\n      # Winches, rigged right\n    button_text: Talk to us\n  - type: breadcrumbs\n  - type: spacer";
        $this->sessions()->save($session);

        $response = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id))->assertOk();
        $values = $response->json('values');
        $notes = implode(' ', $response->json('notes'));

        $this->assertSame('45/65', $values['page_builder'][0]['height']);
        $this->assertSame('entry::contact', $values['page_builder'][1]['button_link']);
        $this->assertSame('center', $values['page_builder'][1]['heading'][0]['attrs']['textAlign']);
        $this->assertCount(3, $values['page_builder'][2]['crumbs']);
        $this->assertSame(['assets::'.ContainerAssetSink::PATH], (array) $values['featured_image']);
        $this->assertStringContainsString('A striped placeholder marks each image still to pick: Featured Image; Hero: Image.', $notes);

        // Saved straight to an entry, it links to itself once it exists.
        $entryId = $this->postJson(cp_route('ghostwriter.sessions.entry', $session->id))->assertOk()->json('id');
        $saved = Entry::query()->where('collection', 'pages')->where('slug', 'studio-winch')->first();

        $this->assertSame('entry::'.$saved->id(), $saved->get('page_builder')[2]['crumbs'][2]['link']);
        $this->assertSame('Studio Winch', $saved->get('page_builder')[2]['crumbs'][2]['label']);

        // Switched off, no placeholders.
        config(['ghostwriter.images.placeholders' => false]);
        $values = $this->postJson(cp_route('ghostwriter.sessions.apply', $session->id))->json('values');
        $this->assertArrayNotHasKey('featured_image', $values);
    }
}
