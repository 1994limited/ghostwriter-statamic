<?php

namespace NineteenNinetyFour\Ghostwriter;

use NineteenNinetyFour\Ghostwriter\Contracts\EntryWriter;
use NineteenNinetyFour\Ghostwriter\Drafts\SchemaEntryWriter;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
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
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../resources/prompts' => resource_path('ghostwriter/prompts'),
        ], 'ghostwriter-prompts');

        Permission::group('ghostwriter', 'Ghostwriter', function (): void {
            Permission::register('access ghostwriter')
                ->label('Write content and edit the voice guide with Ghostwriter');
        });

        // Tells the panel on the publish form which collections it may open on.
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
