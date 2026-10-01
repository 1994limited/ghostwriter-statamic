<?php

namespace NineteenNinetyFour\Ghostwriter;

use Illuminate\Support\Facades\Event;
use NineteenNinetyFour\Ghostwriter\Contracts\EntryWriter;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntryMerger;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use NineteenNinetyFour\Ghostwriter\Drafts\BardToMarkdown;
use NineteenNinetyFour\Ghostwriter\Drafts\SchemaEntryWriter;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Events\AddonSettingsSaving;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\User;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
        'hotFile' => __DIR__.'/../resources/dist/hot',
    ];

    /**
     * The package is 1994/ghostwriter-statamic, so Statamic would file its
     * config under "ghostwriter-statamic". It is merged by hand as plain
     * "ghostwriter" instead, which is what every setting is read as.
     */
    protected $config = false;

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/ghostwriter.php', 'ghostwriter');
        $this->publishes([__DIR__.'/../config/ghostwriter.php' => config_path('ghostwriter.php')], 'ghostwriter-config');

        // Resolved late so a project can point `ghostwriter.writer` at its own
        // class, or bind the contract itself in a service provider.
        $this->app->bindIf(EntryWriter::class, fn ($app) => $app->make(config('ghostwriter.writer', SchemaEntryWriter::class)));

        // The prompts are core's, in Statamic's words. A project overrides
        // one by publishing it to resources/ghostwriter/prompts.
        $this->app->singleton(PromptLibrary::class, fn () => new PromptLibrary(
            Vocabulary::statamic(),
            fn (string $name): ?string => is_file($path = resource_path("ghostwriter/prompts/{$name}.md")) ? (string) file_get_contents($path) : null,
        ));

        // Core's text classes, set up the way Statamic stores entries: a
        // rewritten draft leaves out what it does not hold rather than
        // copying it back, grid rows keep their IDs, and Bard is node trees.
        $this->app->bind(EntryMerger::class, fn () => new EntryMerger(keepMissing: false, mergeRows: true));
        $this->app->bind(EntrySimplifier::class, fn ($app) => new EntrySimplifier(
            richText: fn (mixed $value) => is_array($value) ? $app->make(BardToMarkdown::class)->convert($value) : trim((string) $value),
        ));
    }

    public function bootAddon(): void
    {
        $this->publishes([
            PromptLibrary::directory() => resource_path('ghostwriter/prompts'),
        ], 'ghostwriter-prompts');

        Permission::group('ghostwriter', 'Ghostwriter', function (): void {
            Permission::register('access ghostwriter')
                ->label('Write content and edit the voice guide with Ghostwriter');
        });

        // Tells the panel on the publish form which collections it may open on.
        // The "Show Get started" setting acts on Ghostwriter's own state and
        // is not kept in the settings file.
        Event::listen(AddonSettingsSaving::class, function (AddonSettingsSaving $event): void {
            if ($event->settings->addon()->id() !== Settings::ADDON) {
                return;
            }

            if ($event->settings->get('show_get_started')) {
                app(Onboarding::class)->hide(false);
            }

            $event->settings->set('show_get_started', null);
        });

        Statamic::provideToScript(['ghostwriter' => fn () => [
            'enabled' => (bool) User::current()?->can('access ghostwriter'),
            'collections' => app(TypeRepository::class)->collections()->map->handle()->values()->all(),
            'url' => cp_route('ghostwriter.index'),
        ]]);

        Nav::extend(function ($nav): void {
            $nav->tools('Ghostwriter')
                ->route('ghostwriter.index')
                ->icon(Settings::ICON)
                ->can('access ghostwriter')
                ->children(array_filter([
                    app(Onboarding::class)->hidden() ? null : $nav->item('Get started')->route('ghostwriter.setup.show')->can('access ghostwriter'),
                    $nav->item('Content plan')->route('ghostwriter.plan.show')->can('access ghostwriter'),
                    $nav->item('Voice guide')->route('ghostwriter.voice.show')->can('access ghostwriter'),
                    $nav->item('Image style')->route('ghostwriter.imagery.show')->can('access ghostwriter'),
                    ($settings = app(Settings::class)->url())
                        ? $nav->item('Settings')->url($settings)->can('edit '.Settings::ADDON.' settings')
                        : null,
                ]));
        });
    }
}
