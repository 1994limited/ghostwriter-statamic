<?php

namespace NineteenNinetyFour\Ghostwriter;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use NineteenNinetyFour\Ghostwriter\Ai\ConfigCredentials;
use NineteenNinetyFour\Ghostwriter\Ai\ConfigProviderSettings;
use NineteenNinetyFour\Ghostwriter\Ai\EncryptedProviderKeys;
use NineteenNinetyFour\Ghostwriter\Ai\ModelCheck;
use NineteenNinetyFour\Ghostwriter\Contracts\EntryWriter;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\ConnectedCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\ConnectsProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\OpenRouterConnection;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\GuzzleHttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderKeys;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequests;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\Waiting;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\WaitingStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\ModelInputGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Ports\LibraryTokens;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio as CoreStudio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\StudioOptions;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntryMerger;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use NineteenNinetyFour\Ghostwriter\Drafts\BardDialect;
use NineteenNinetyFour\Ghostwriter\Drafts\SchemaEntryWriter;
use NineteenNinetyFour\Ghostwriter\Http\Controllers\FinishController;
use NineteenNinetyFour\Ghostwriter\Http\Middleware\StockPreviewsInLivePreview;
use NineteenNinetyFour\Ghostwriter\Stock\EncryptedLibraryTokens;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use NineteenNinetyFour\Ghostwriter\Storage\FileGuideStore;
use NineteenNinetyFour\Ghostwriter\Storage\FileImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Storage\FileKindStore;
use NineteenNinetyFour\Ghostwriter\Storage\FileLock;
use NineteenNinetyFour\Ghostwriter\Storage\FilePlanStore;
use NineteenNinetyFour\Ghostwriter\Storage\FileSessionStore;
use NineteenNinetyFour\Ghostwriter\Storage\FileStockImageStore;
use NineteenNinetyFour\Ghostwriter\Storage\FileWaitingStore;
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
        // A key from "Connect with OpenRouter" is kept encrypted; a key in
        // .env always wins over it.
        $this->app->bindIf(ProviderKeys::class, EncryptedProviderKeys::class);
        $this->app->bindIf(ConnectsProvider::class, fn ($app) => new OpenRouterConnection(
            new ConfigCredentials,
            $app->make(ProviderKeys::class),
            $app->make(HttpClients::class),
            keyLabel: 'Ghostwriter ('.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'Statamic').')',
            baseUrl: (new ConfigProviderSettings($app->make(Settings::class)))->baseUrl('openrouter'),
            logger: Log::channel(config('ghostwriter.log_channel')),
        ));
        $this->app->singleton(Providers::class, fn ($app) => new Providers(
            new ConnectedCredentials(new ConfigCredentials, $app->make(ProviderKeys::class)),
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

        // Writing, planning and learning the site are core's Studio, with
        // Statamic's options. With ghostwriter.debug.log_replies on, a reply
        // that can't be read is logged whole; otherwise only what was wrong.
        $this->app->singleton(CoreStudio::class, fn ($app) => new CoreStudio(
            $app->make(Providers::class),
            $app->make(PromptLibrary::class),
            Log::channel(config('ghostwriter.log_channel')),
            StudioOptions::statamic(logReplies: (bool) config('ghostwriter.debug.log_replies', false)),
            $app->make(ModelInputGuard::class),
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
            guard: $app->make(ModelInputGuard::class),
        ));

        // No image from a library whose licence forbids AI use (Getty,
        // iStock and the other paid libraries) goes to any model: not as a
        // reference for photo picking or making, nor as a sample for the
        // image style guide. The guard knows them by the ledger, their file
        // names and their embedded credit.
        $this->app->bind(ModelInputGuard::class, fn ($app) => new ModelInputGuard($app->make(StockImageStore::class), Log::channel(config('ghostwriter.log_channel'))));

        // Core's text classes, set up the way Statamic stores entries: a
        // rewritten draft leaves out what it does not hold rather than
        // copying it back, grid rows keep their IDs, and Bard is node trees.
        $this->app->bind(EntryMerger::class, fn () => new EntryMerger(keepMissing: false, mergeRows: true));
        $this->app->bind(EntrySimplifier::class, fn ($app) => PatternFinder::simplifier($app->make(BardDialect::class)));

        // Core's domain: sessions, the content plan, kinds of content, the
        // guides, image requests and the queue-waiting marks, kept in files
        // as they always have been, with a file lock. The options read the
        // config each time, as sharing can be switched at any time.
        $this->app->bindIf(SessionStore::class, FileSessionStore::class);
        $this->app->bindIf(PlanStore::class, FilePlanStore::class);
        $this->app->bindIf(KindStore::class, FileKindStore::class);
        $this->app->bindIf(GuideStore::class, FileGuideStore::class);
        $this->app->bindIf(ImageRequestStore::class, FileImageRequestStore::class);
        $this->app->bindIf(WaitingStore::class, FileWaitingStore::class);
        $this->app->bindIf(Lock::class, FileLock::class);
        // The stock image ledger: one YAML file per record under content/,
        // read once per request while unchanged.
        $this->app->singletonIf(StockImageStore::class, FileStockImageStore::class);
        // Paid libraries' connected-account tokens, encrypted with the app key.
        $this->app->bindIf(LibraryTokens::class, EncryptedLibraryTokens::class);
        $this->app->bind(DomainOptions::class, fn ($app) => DomainOptions::statamic(
            shared: (bool) config('ghostwriter.shared_conversations', true),
            jobTimeout: $app->make(Settings::class)->timeout(),
        ));
        $clock = fn () => Carbon::now()->toImmutable();
        $this->app->bind(SessionGuard::class, fn ($app) => new SessionGuard($app->make(SessionStore::class), $app->make(Lock::class), $app->make(DomainOptions::class), $clock));
        $this->app->bind(Plan::class, fn ($app) => new Plan($app->make(PlanStore::class), $app->make(Lock::class), Format::Statamic));
        $this->app->bind(StockImages::class, fn ($app) => new StockImages($app->make(StockImageStore::class), $app->make(Lock::class), $app->make(DomainOptions::class), $clock));
        $this->app->bind(ImageRequests::class, fn ($app) => new ImageRequests($app->make(ImageRequestStore::class), $app->make(Lock::class), $app->make(DomainOptions::class), $clock));
        // The sync queue runs work itself, after the response: nothing to wait for.
        $this->app->bind(Waiting::class, fn ($app) => new Waiting($app->make(WaitingStore::class), $app->make(DomainOptions::class), runsItself: config('queue.default') === 'sync', clock: $clock));

        // Core's layout algorithms, as Statamic stores entries: Bard for rich
        // text, `entry::id` links, and new sets and rows with IDs of their own.
        // A link the house style can't settle points at `#gw-link:<hint>`,
        // a link to choose that Finish this page finds, not example.com.
        $this->app->singleton(Layouts::class, fn ($app) => new Layouts(LayoutOptions::statamic()->withLinkSentinels(), $app->make(BardDialect::class), new StatamicLinks));
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
            $this->registerSettingsBlueprint(fn () => app(Settings::class)->lockOverridden(app(Settings::class)->withOpenRouter(app(Settings::class)->withStock(app(Settings::class)->withKeyStatus(YAML::file($path)->parse()), app(StockLibraries::class)), app(ConnectsProvider::class))));
        }

        return $this;
    }

    /**
     * Stock previews kept to the libraries' terms: expired comps deleted,
     * unused previews removed, unknown licence outcomes settled. Hourly, so
     * a licence in doubt is settled soon after its ten minutes are up.
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('ghostwriter:stock-cleanup')->hourly()->withoutOverlapping();
    }

    public function bootAddon(): void
    {
        $this->publishes([
            PromptLibrary::directory() => resource_path('ghostwriter/prompts'),
        ], 'ghostwriter-prompts');

        Permission::group('ghostwriter', 'Ghostwriter', function (): void {
            Permission::register('access ghostwriter')
                ->label('Write content and edit the voice guide with Ghostwriter');

            // Licensing spends money on the site's own account with a paid
            // library, so it is a permission of its own, given to nobody by
            // default (super users have it).
            Permission::register('license stock images')
                ->label('License stock images from paid libraries (spends from your account)');
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
            $event->settings->set('stock_libraries', null);
            $event->settings->set('openrouter_connection', null);

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

        // Live Preview shows a stock preview's comp, to signed-in editors only.
        $this->app['router']->pushMiddlewareToGroup('statamic.web', StockPreviewsInLivePreview::class);

        Statamic::provideToScript(['ghostwriter' => fn () => [
            'enabled' => (bool) User::current()?->can('access ghostwriter'),
            'collections' => app(TypeRepository::class)->collections()->map->handle()->values()->all(),
            'url' => cp_route('ghostwriter.index'),
            // A draft put into a new (or unpublished) entry's form switches
            // its Published toggle off, for the editor to switch on.
            'drafts_unpublished' => (bool) config('ghostwriter.drafts_unpublished', true),
            // Finish this page: how each person last left the guide (it
            // starts minimised), and whether it opens after a draft.
            'finish' => [
                'guide' => User::current()?->getPreference(FinishController::PREFERENCE) === FinishController::OPEN ? FinishController::OPEN : FinishController::MINIMISED,
                'open_after_draft' => app(Settings::class)->openGuideAfterDraft(),
            ],
        ]]);

        Nav::extend(function ($nav): void {
            $nav->tools('Ghostwriter')
                ->route('ghostwriter.index')
                ->icon(Settings::ICON)
                ->can('access ghostwriter')
                ->children(array_filter([
                    app(Onboarding::class)->hidden() ? null : $nav->item('Get started')->route('ghostwriter.setup.show')->can('access ghostwriter'),
                    $nav->item('Overview')->route('ghostwriter.index')->can('access ghostwriter'),
                    $nav->item('Content plan')->route('ghostwriter.plan.show')->can('access ghostwriter'),
                    $nav->item('Voice guide')->route('ghostwriter.voice.show')->can('access ghostwriter'),
                    $nav->item('Image style')->route('ghostwriter.imagery.show')->can('access ghostwriter'),
                    $nav->item('Stock images')->route('ghostwriter.stock.index')->can('access ghostwriter'),
                    ($settings = app(Settings::class)->url())
                        ? $nav->item('Settings')->url($settings)->can('edit '.Settings::ADDON.' settings')
                        : null,
                ]));
        });
    }
}
