<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Onboarding;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Widgets\Ghostwriter;
use Statamic\Contracts\Addons\SettingsRepository;
use Statamic\Facades\Addon;
use Statamic\Facades\Collection;

/**
 * Get started: the steps read from the site's state, hiding and showing
 * them, and the dashboard widget.
 */
class SetupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeArticlesCollection();
        $this->makeArticle('one', 'One', 'A paragraph long enough to count as a sample of the site writing.');
    }

    public function test_each_step_reads_the_state_of_the_site(): void
    {
        $this->signIn();

        $steps = collect($this->getJson(cp_route('ghostwriter.setup.status'))->assertOk()->json('steps'))->keyBy('key');

        $this->assertSame(['key', 'collections', 'voice', 'kinds', 'imagery', 'plan', 'write'], $steps->keys()->all());
        $this->assertTrue($steps['key']['done']);
        $this->assertTrue($steps['collections']['done']);
        $this->assertFalse($steps['voice']['done']);
        $this->assertSame('post', $steps['voice']['action']['type']);
        $this->assertTrue($steps['imagery']['optional']);
        $this->assertFalse($steps['write']['done']);

        // A guide written, a session started: the steps follow.
        app(GuideStore::class)->saveGuide(new Guide(Guide::VOICE, '# Voice'));
        $this->sessions()->save($this->makeSession('any:articles', []));
        $this->makeType();

        $after = $this->getJson(cp_route('ghostwriter.setup.status'))->json();
        $steps = collect($after['steps'])->keyBy('key');

        $this->assertTrue($steps['voice']['done']);
        $this->assertSame('link', $steps['voice']['action']['type']);
        $this->assertTrue($steps['kinds']['done']);
        $this->assertSame('1 kind learned.', $steps['kinds']['detail']);
        $this->assertTrue($steps['write']['done']);
        // Only the required steps are counted, so the bar reaches the end.
        $this->assertSame(5, $after['progress']['done']);
        $this->assertSame(5, $after['progress']['total']);
        $this->assertTrue($after['progress']['complete'], 'Every required step is done; the optional ones do not count.');
        $this->assertSame('imagery', $after['progress']['next']['key'], 'With the required steps done, the optional ones come next.');
        $this->assertTrue($after['progress']['next']['optional']);

        // Without a key, the first step says which to add.
        $this->withoutKeys('anthropic');
        $first = $this->getJson(cp_route('ghostwriter.setup.status'))->json('steps.0');
        $this->assertFalse($first['done']);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $first['detail']);
    }

    public function test_the_next_step_is_the_first_required_one_still_to_do(): void
    {
        $this->signIn();

        $progress = $this->getJson(cp_route('ghostwriter.setup.status'))->json('progress');

        $this->assertSame('voice', $progress['next']['key']);
        $this->assertSame(2, $progress['done']);
        $this->assertSame(5, $progress['total']);
        $this->assertFalse($progress['complete']);
    }

    public function test_only_those_who_look_after_the_settings_may_hide_get_started(): void
    {
        $this->signIn();

        $this->assertFalse($this->getJson(cp_route('ghostwriter.setup.status'))->json('progress.can_toggle'));
        $this->postJson(cp_route('ghostwriter.setup.hide'), ['hidden' => true])->assertForbidden();
        $this->assertFalse(app(Onboarding::class)->hidden());
    }

    public function test_collections_are_chosen_in_place_by_those_who_may_change_the_settings(): void
    {
        Collection::make('pages')->title('Pages')->save();
        // As stored: a list set in config is shown over it, not saved into it.
        $saved = fn (string $key) => app(SettingsRepository::class)->stored(Settings::ADDON)[$key] ?? null;

        $this->signIn();
        $this->postJson(cp_route('ghostwriter.setup.collections'), ['collections' => ['articles']])->assertForbidden();

        $this->signInAsManager();

        $details = $this->postJson(cp_route('ghostwriter.setup.collections'), ['collections' => ['articles', 'nowhere'], 'voice_collections' => ['pages']])->assertOk()->json('details');

        $this->assertSame(['articles'], $saved('collections'));
        $this->assertSame(['pages'], $saved('voice_collections'));
        $this->assertSame(['articles'], collect($details['collections'])->where('write_for', true)->pluck('handle')->all());

        // Every one ticked means all of them, new ones included.
        $this->postJson(cp_route('ghostwriter.setup.collections'), ['collections' => ['articles', 'pages']])->assertOk();
        $this->assertEmpty($saved('collections'));

        // None ticked would mean all, so it is refused.
        $this->postJson(cp_route('ghostwriter.setup.collections'), ['collections' => []])->assertStatus(422);

        // A list set in config wins, is shown locked, and is left alone.
        config(['ghostwriter.collections' => ['pages']]);
        $this->assertTrue($this->getJson(cp_route('ghostwriter.setup.status'))->json('details.collections_locked'));
        $this->postJson(cp_route('ghostwriter.setup.collections'), ['collections' => ['articles']])->assertOk();
        $this->assertEmpty($saved('collections'));
    }

    public function test_get_started_can_be_hidden_and_shown_again(): void
    {
        $this->signInAsManager();

        $this->get(cp_route('ghostwriter.setup.show'))->assertOk();
        $this->assertTrue($this->getJson(cp_route('ghostwriter.setup.status'))->json('progress.can_toggle'));
        $this->assertFalse(app(Onboarding::class)->hidden());

        $this->postJson(cp_route('ghostwriter.setup.hide'), ['hidden' => true])->assertOk()->assertJsonPath('hidden', true);
        $this->assertTrue(app(Onboarding::class)->progress()['hidden']);

        $this->postJson(cp_route('ghostwriter.setup.hide'), ['hidden' => false])->assertJsonPath('hidden', false);

        // The setting brings it back too, and is not kept.
        app(Onboarding::class)->hide(true);
        $settings = Addon::get('1994/ghostwriter-statamic')->settings();
        $settings->set('show_get_started', true)->save();

        $this->assertFalse(app(Onboarding::class)->hidden());
        $this->assertArrayNotHasKey('show_get_started', array_filter($settings->raw(), fn ($value) => $value !== null));
    }

    public function test_settings_are_only_linked_for_those_who_may_change_them(): void
    {
        $this->signIn();

        $this->get(cp_route('ghostwriter.index'))->assertOk()->assertInertia(fn ($page) => $page->where('settings_url', null));

        $steps = collect($this->getJson(cp_route('ghostwriter.setup.status'))->json('steps'))->keyBy('key');
        $this->assertNull($steps['key']['action']);
        $this->assertNull($steps['collections']['action']);
        $this->assertNull($this->getJson(cp_route('ghostwriter.setup.status'))->json('details.settings_url'));

        $this->signInAsManager();

        $this->get(cp_route('ghostwriter.index'))->assertInertia(fn ($page) => $page->whereType('settings_url', 'string'));
        $this->assertSame('link', $this->getJson(cp_route('ghostwriter.setup.status'))->json('steps.0.action.type'));
    }

    public function test_the_dashboard_widget_shows_progress_and_pieces_for_those_allowed(): void
    {
        $this->signIn();
        $this->sessions()->save($this->makeSession('any:articles', ['subject' => 'Something long enough to be a title']));

        $widget = new Ghostwriter;
        $widget->setConfig(['limit' => 1]);

        $props = $widget->component()->toArray()['props'];

        $this->assertCount(1, $props['inProgress']);
        $this->assertSame(0, $props['moreInProgress']);
        $this->assertSame(['articles'], array_column($props['collections'], 'handle'));
        $this->assertFalse($props['setup']['complete']);

        $this->signIn(permitted: false);
        $this->assertNull($widget->component());
    }
}
