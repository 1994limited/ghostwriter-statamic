<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use NineteenNinetyFour\Ghostwriter\Ai\ModelCheck;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Addon;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\User;
use Statamic\Facades\YAML;

/**
 * Where each setting comes from: the config wins over the settings screen,
 * which shows the field locked; the time limit is config only; a model
 * that belongs to another provider is warned about.
 */
class SettingsTest extends TestCase
{
    public function test_a_value_set_in_config_wins_over_the_settings_screen(): void
    {
        $settings = app(Settings::class);
        $addon = Addon::get(Settings::ADDON)->settings();

        $addon->set(['provider' => 'gemini', 'model' => 'gemini-pro', 'suggest_kinds' => false, 'collections' => ['pages']])->save();

        // Set in config: that wins.
        config(['ghostwriter.provider' => 'openai', 'ghostwriter.collections' => ['articles']]);
        $this->assertSame('openai', $settings->provider());
        $this->assertSame(['articles'], $settings->collections());
        $this->assertTrue($settings->isOverridden('provider'));

        // Left blank in config: the settings screen decides.
        config(['ghostwriter.provider' => null, 'ghostwriter.collections' => [], 'ghostwriter.suggest_kinds' => null]);
        $this->assertSame('gemini', $settings->provider());
        $this->assertSame('gemini-pro', $settings->model());
        $this->assertSame(['pages'], $settings->collections());
        $this->assertFalse($settings->suggestsKinds());
        $this->assertFalse($settings->isOverridden('provider'));

        // A switch turned off in config is set, not blank.
        config(['ghostwriter.images.placeholders' => false]);
        $this->assertTrue($settings->isOverridden('placeholder_images'));
        $this->assertFalse($settings->placeholderImages());

        // Neither: the defaults.
        $addon->set(['provider' => null, 'model' => null, 'suggest_kinds' => null, 'collections' => null])->save();
        config(['ghostwriter.images.placeholders' => null]);
        $this->assertSame('anthropic', $settings->provider());
        $this->assertNull($settings->model());
        $this->assertTrue($settings->suggestsKinds());
        $this->assertTrue($settings->placeholderImages());
    }

    public function test_the_settings_screen_locks_and_labels_what_the_config_sets(): void
    {
        config(['ghostwriter.provider' => 'openai', 'ghostwriter.model' => null]);

        $fields = collect(Addon::get(Settings::ADDON)->settingsBlueprint()->fields()->all());

        $this->assertSame('read_only', $fields['provider']->visibility());
        $this->assertStringContainsString('Set in config/ghostwriter.php (or .env) to "openai"', $fields['provider']->get('instructions'));
        $this->assertNotSame('read_only', $fields['model']->visibility());
        $this->assertSame('Mark images still to choose', $fields['placeholder_images']->display());
    }

    public function test_openverse_is_a_setting_and_the_keys_are_listed_without_their_values(): void
    {
        $settings = app(Settings::class);

        $this->assertTrue($settings->openverse());

        Addon::get(Settings::ADDON)->settings()->set(['openverse' => false])->save();
        $this->assertFalse($settings->openverse());
        $this->assertNotContains('openverse', app(StockSearch::class)->sources());

        config(['ghostwriter.images.openverse' => true]);
        $this->assertTrue($settings->openverse());
        $this->assertTrue($settings->isOverridden('openverse'));
        $this->assertContains('openverse', app(StockSearch::class)->sources());

        config(['ghostwriter.keys.openai' => 'sk-secret-value', 'ghostwriter.images.pexels_key' => 'pexels-secret']);

        $this->assertSame(
            ['ANTHROPIC_API_KEY' => true, 'OPENAI_API_KEY' => true, 'GEMINI_API_KEY' => false, 'UNSPLASH_ACCESS_KEY' => false, 'PIXABAY_API_KEY' => false, 'PEXELS_API_KEY' => true],
            $settings->keyStatus(),
        );

        $html = collect(Addon::get(Settings::ADDON)->settingsBlueprint()->fields()->all())['key_status']->get('html');

        $this->assertStringContainsString('GEMINI_API_KEY</code>', $html);
        $this->assertStringContainsString('Not set', $html);
        $this->assertStringNotContainsString('sk-secret-value', $html);
        $this->assertStringNotContainsString('pexels-secret', $html);
    }

    public function test_the_time_limit_is_config_only_and_five_minutes_by_default(): void
    {
        config(['ghostwriter.timeout' => null]);
        $this->assertSame(300, app(Settings::class)->timeout());
        $this->assertSame(960, app(Settings::class)->jobTimeout());

        config(['ghostwriter.timeout' => 600]);
        $this->assertSame(600, app(Settings::class)->timeout());

        $this->assertArrayNotHasKey('timeout', collect(Addon::get(Settings::ADDON)->settingsBlueprint()->fields()->all())->all());
    }

    public function test_a_model_from_another_provider_is_warned_about_when_the_settings_are_saved(): void
    {
        $check = app(ModelCheck::class);

        $this->assertNull($check->mismatch('anthropic', 'claude-opus-5-5'));
        $this->assertNull($check->mismatch('openai', 'gpt-6.1-sol'));
        $this->assertNull($check->mismatch('gemini', 'models/gemini-3.8-flash'));
        $this->assertNull($check->mismatch('anthropic', 'something-new'), 'An unknown name is let through.');
        $this->assertNull($check->mismatch('anthropic', ''));
        $this->assertStringContainsString('looks like a ChatGPT (OpenAI) model', (string) $check->mismatch('anthropic', 'gpt-6.1-sol'));
        $this->assertStringContainsString('looks like a Gemini (Google) model', (string) $check->mismatch('openai', 'gemini-3.8-flash', 'Image model'));

        config(['ghostwriter.provider' => null]);
        Toast::clear();

        Addon::get(Settings::ADDON)->settings()->set(['provider' => 'anthropic', 'model' => 'gpt-6.1-sol'])->save();

        $messages = array_map(fn ($toast) => $toast->toArray()['message'], Toast::all());
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('"gpt-6.1-sol" looks like a ChatGPT (OpenAI) model', $messages[0]);

        // Saved all the same.
        $this->assertSame('gpt-6.1-sol', app(Settings::class)->model());
    }

    public function test_a_locked_field_shows_the_value_the_config_sets_and_saving_keeps_the_stored_one(): void
    {
        config(['ghostwriter.provider' => null]);
        $addon = Addon::get(Settings::ADDON);
        $addon->settings()->set(['openverse' => true, 'placeholder_images' => true, 'model' => 'claude-opus-5-5', 'collections' => ['pages']])->save();

        config([
            'ghostwriter.images.openverse' => false,
            'ghostwriter.images.placeholders' => false,
            'ghostwriter.provider' => 'gemini',
            'ghostwriter.collections' => ['articles'],
        ]);

        $user = User::make()->email('admin@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        // Every lockable field shows what applies, not what was saved.
        $values = $this->getJson(cp_route('addons.settings.edit', $addon->slug()))->assertOk()->json('values');

        $this->assertFalse($values['openverse']);
        $this->assertFalse($values['placeholder_images']);
        $this->assertSame('gemini', $values['provider']);
        $this->assertSame(['articles'], $values['collections']);
        $this->assertSame('claude-opus-5-5', $values['model'], 'A field the config leaves alone shows what was saved.');

        // Saving the screen sends the locked values back; what was stored for
        // them is kept, so taking the config away brings it back.
        $this->patchJson(cp_route('addons.settings.update', $addon->slug()), array_merge($values, ['model' => 'claude-sonnet-5']))->assertOk();

        $stored = YAML::file(resource_path('addons/ghostwriter-statamic.yaml'))->parse();
        $this->assertTrue($stored['openverse']);
        $this->assertTrue($stored['placeholder_images']);
        $this->assertSame(['pages'], $stored['collections']);
        $this->assertNull($stored['provider'] ?? null);
        $this->assertSame('claude-sonnet-5', $stored['model']);

        config(['ghostwriter.images.openverse' => null]);
        $this->assertTrue(app(Settings::class)->openverse());
        $this->assertTrue($this->getJson(cp_route('addons.settings.edit', $addon->slug()))->json('values.openverse'));
    }
}
