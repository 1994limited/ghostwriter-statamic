<?php

namespace NineteenNinetyFour\Ghostwriter;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use NineteenNinetyFour\Ghostwriter\Ai\ConfigCredentials;
use NineteenNinetyFour\Ghostwriter\Ai\ConfigProviderSettings;
use NineteenNinetyFour\Ghostwriter\Ai\ModelCheck;
use NineteenNinetyFour\Ghostwriter\Contracts\EntryWriter;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\GuzzleHttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntryMerger;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use NineteenNinetyFour\Ghostwriter\Drafts\BardToMarkdown;
use NineteenNinetyFour\Ghostwriter\Drafts\SchemaEntryWriter;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Addons\SettingsRepository;
use Statamic\Events\AddonSettingsSaving;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\Permission;
use Statamic\Facades\User;
use Statamic\Facades\YAML;
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

        // A field the config locks shows the config's value on the settings screen.
        $this->app->extend(SettingsRepository::class, fn (SettingsRepository $repository) => $repository instanceof ConfiguredSettingsRepository ? $repository : new ConfiguredSettingsRepository($repository));

        // Resolved late so a project can point `ghostwriter.writer` at its own
        // class, or bind the contract itself in a service provider.
        $this->app->bindIf(EntryWriter::class, fn ($app) => $app->make(config('ghostwriter.writer', SchemaEntryWriter::class)));

        // One connection to the models for the whole request or worker, from
        // Ghostwriter Core. Keys and settings are read on every call. A
        // project can bind its own HttpClients, to go through a proxy, say.
        $this->app->bindIf(HttpClients::class, GuzzleHttpClients::class);
        $this->app->singleton(Providers::class, fn ($app) => new Providers(
            new ConfigCredentials,
            $app->make(HttpClients::class),
            new ConfigProviderSettings($app->make(Settings::class)),
            Log::channel(config('ghostwriter.log_channel')),
        ));

        // The prompts are core's, in Statamic's words. A project overrides
        // one by publishing it to resources/ghostwriter/prompts.
        $this->app->singleton(PromptLibrary::class, fn () => new PromptLibrary(
            Vocabulary::statamic(),
            fn (string $name): ?string => is_file($path = resource_path("ghostwriter/prompts/{$name}.md")) ? (string) file_get_contents($path) : null,
        ));

        // Photo search is core's too: the libraries, choosing the searches,
        // judging the results against the page and the images already
        // there, and a second round when nothing fits. Openverse can be
        // switched off on the settings screen (or in config, which wins) at
        // any time, so it is read on each search.
        $this->app->singleton(StockSearch::class, fn ($app) => new StockSearch(
            $app->make(HttpClients::class),
            new ConfigCredentials,
            openverse: fn (): bool => $app->make(Settings::class)->openverse(),
            logger: Log::channel(config('ghostwriter.log_channel')),
        ));
        $this->app->singleton(PhotoFinder::class, fn ($app) => new PhotoFinder(
            $app->make(StockSearch::class),
            $app->make(Providers::class),
            $app->make(PromptLibrary::class),
            Log::channel(config('ghostwriter.log_channel')),
        ));

        // Core's text classes, set up the way Statamic stores entries: a
        // rewritten draft leaves out what it does not hold rather than
        // copying it back, grid rows keep their IDs, and Bard is node trees.
        $this->app->bind(EntryMerger::class, fn () => new EntryMerger(keepMissing: false, mergeRows: true));
        $this->app->bind(EntrySimplifier::class, fn ($app) => new EntrySimplifier(
            richText: fn (mixed $value) => is_array($value) ? $app->make(BardToMarkdown::class)->convert($value) : trim((string) $value),
        ));
    }

    /**
     * The settings screen, with each field that config/ghostwriter.php (or
     * .env) sets locked and labelled, since the config wins.
     */
    protected function bootSettingsBlueprint()
    {
        parent::bootSettingsBlueprint();

        $path = __DIR__.'/../resources/blueprints/settings.yaml';

        if ($this->getAddon()->hasSettingsBlueprint()) {
            $this->registerSettingsBlueprint(fn () => app(Settings::class)->withKeyStatus(app(Settings::class)->lockOverridden(YAML::file($path)->parse())));
        }

        return $this;
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
            $event->settings->set('key_status', null);

            // A locked field shows, and so sends back, the config's value.
            // What was saved for it stays, for when the config lets go.
            $stored = ($repository = app(SettingsRepository::class)) instanceof ConfiguredSettingsRepository ? $repository->stored(Settings::ADDON) : [];

            foreach (app(Settings::class)->configured() as $key => $value) {
                if (($event->settings->raw()[$key] ?? null) === $value) {
                    $event->settings->set($key, $stored[$key] ?? null);
                }
            }

            // A model name that belongs to another provider is let through,
            // as new models appear all the time, but said out loud.
            $settings = app(Settings::class);
            $check = app(ModelCheck::class);
            $provider = $settings->fromConfig('provider') ?? ($event->settings->get('provider') ?: 'anthropic');
            $imageProvider = $settings->fromConfig('image_provider') ?? ($event->settings->get('image_provider') ?: null);

            foreach (array_filter([
                $check->mismatch($provider, $settings->fromConfig('model') ?? $event->settings->get('model')),
                $check->mismatch($imageProvider, $settings->fromConfig('image_model') ?? $event->settings->get('image_model'), 'Image model'),
            ]) as $warning) {
                Toast::error($warning)->duration(12000);
            }
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
