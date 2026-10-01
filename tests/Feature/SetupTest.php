<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use NineteenNinetyFour\Ghostwriter\Onboarding;
use NineteenNinetyFour\Ghostwriter\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Sessions\SessionRepository;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;
use NineteenNinetyFour\Ghostwriter\Widgets\Ghostwriter;
use Statamic\Facades\Addon;

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
        app(VoiceGuide::class)->save('# Voice');
        app(SessionRepository::class)->save(Session::start('any:articles', []));
        $this->makeType();

        $after = $this->getJson(cp_route('ghostwriter.setup.status'))->json();
        $steps = collect($after['steps'])->keyBy('key');

        $this->assertTrue($steps['voice']['done']);
        $this->assertSame('link', $steps['voice']['action']['type']);
        $this->assertTrue($steps['kinds']['done']);
        $this->assertSame('1 kind learned.', $steps['kinds']['detail']);
        $this->assertTrue($steps['write']['done']);
        $this->assertSame(5, $after['progress']['done']);
        $this->assertTrue($after['progress']['complete'], 'Every required step is done; the optional ones do not count.');
        $this->assertSame('imagery', $after['progress']['next']['key']);

        // Without a key, the first step says which to add.
        config(['ai.providers.anthropic.key' => null]);
        $first = $this->getJson(cp_route('ghostwriter.setup.status'))->json('steps.0');
        $this->assertFalse($first['done']);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $first['detail']);
    }

    public function test_get_started_can_be_hidden_and_shown_again(): void
    {
        $this->signIn();

        $this->get(cp_route('ghostwriter.setup.show'))->assertOk();
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

    public function test_the_dashboard_widget_shows_progress_and_pieces_for_those_allowed(): void
    {
        $this->signIn();
        app(SessionRepository::class)->save(Session::start('any:articles', ['subject' => 'Something long enough to be a title']));

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
