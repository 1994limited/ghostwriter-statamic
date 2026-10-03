<?php

namespace NineteenNinetyFour\Ghostwriter;

use NineteenNinetyFour\Ghostwriter\Ai\ConfigCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use Statamic\Facades\Addon;
use Statamic\Facades\User;

/**
 * Where Ghostwriter's options come from: config/ghostwriter.php (and so
 * .env) first, then the addon's settings screen in the Control Panel. A
 * value set in code wins, so a site can fix one per environment; the
 * settings screen shows that field locked, with a note saying so.
 */
class Settings
{
    public const ADDON = '1994/ghostwriter-statamic';

    /** Ghostwriter's mark as the pack's 14-grid stroke icon, in currentColor. */
    public const ICON = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path d="M2.5 12.5V6a4.5 4.5 0 0 1 9 0v4l-2.5 2.5Z" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.5 10H9v2.5" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"></path><circle cx="5.4" cy="6.25" r=".75" fill="currentColor"></circle><circle cx="8.6" cy="6.25" r=".75" fill="currentColor"></circle></svg>';

    /** Providers Ghostwriter makes images with. */
    public const IMAGE_PROVIDERS = ['openai', 'gemini'];

    /** Seconds one call to a model may take, unless the config says otherwise. */
    public const TIMEOUT = 300;

    /** Each field on the settings screen, and the config key that overrides it. */
    public const IN_CONFIG = [
        'collections' => 'ghostwriter.collections',
        'voice_collections' => 'ghostwriter.voice.collections',
        'suggest_kinds' => 'ghostwriter.suggest_kinds',
        'provider' => 'ghostwriter.provider',
        'model' => 'ghostwriter.model',
        'image_provider' => 'ghostwriter.images.provider',
        'image_model' => 'ghostwriter.images.model',
        'placeholder_images' => 'ghostwriter.images.placeholders',
        'openverse' => 'ghostwriter.images.openverse',
        'stock_on_publish' => 'ghostwriter.stock.on_publish',
        'stock_default_source' => 'ghostwriter.stock.default_source',
        'stock_include_editorial' => 'ghostwriter.stock.include_editorial',
    ];

    /** When a page holding an unlicensed preview is published. */
    public const BLOCK = 'block';

    public const WARN = 'warn';

    public function provider(): string
    {
        return (string) ($this->value('provider') ?? 'anthropic');
    }

    public function model(): ?string
    {
        return $this->value('model') ?: null;
    }

    /**
     * The provider images are made with: openai or gemini. Null means
     * "whichever has a key", which is also what a provider that no longer
     * makes images for Ghostwriter (xai) falls back to.
     */
    public function imageProvider(): ?string
    {
        $provider = $this->value('image_provider');

        return in_array($provider, self::IMAGE_PROVIDERS, true) ? $provider : null;
    }

    public function imageModel(): ?string
    {
        return $this->value('image_model') ?: null;
    }

    /**
     * Seconds one call to a model may take. Set in config only: it is a
     * matter for whoever runs the queue, not for editors.
     */
    public function timeout(): int
    {
        return (int) (config('ghostwriter.timeout') ?: self::TIMEOUT);
    }

    /**
     * Seconds a queued job may run: three attempts at one call, as busy and
     * rate-limited calls are retried, and a minute to spare.
     */
    public function jobTimeout(): int
    {
        return $this->timeout() * 3 + 60;
    }

    /**
     * The command that starts a worker for the queue Ghostwriter's work
     * goes to, for the notice shown when nothing picks it up.
     */
    public function workerCommand(): string
    {
        $connection = (string) config('queue.default');
        $queue = (string) (config("queue.connections.{$connection}.queue") ?: 'default');

        return 'php artisan queue:work'.($queue !== 'default' ? " --queue={$queue}" : '');
    }

    /**
     * Whether a new entry's empty image fields get a striped placeholder.
     */
    public function placeholderImages(): bool
    {
        return (bool) ($this->value('placeholder_images') ?? true);
    }

    /**
     * Whether Openverse, which needs no key, is searched for photographs.
     */
    public function openverse(): bool
    {
        return (bool) ($this->value('openverse') ?? true);
    }

    /**
     * Whether a paid photo library is switched on: it is, unless switched
     * off on the settings screen.
     */
    public function stockLibraryEnabled(string $id): bool
    {
        return (bool) ($this->value('stock_'.$id) ?? true);
    }

    /**
     * Where "Search in" starts for someone who hasn't chosen yet: "free",
     * "everything" or a library's ID.
     */
    public function stockDefaultSource(): string
    {
        return (string) ($this->value('stock_default_source') ?? 'free');
    }

    /**
     * Whether "Include editorial images" starts ticked in the image dialog.
     */
    public function stockIncludeEditorial(): bool
    {
        return filter_var($this->value('stock_include_editorial') ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * When a page holding an unlicensed preview is published: block (the
     * default), or warn and let it through.
     */
    public function stockOnPublish(): string
    {
        return $this->value('stock_on_publish') === self::WARN ? self::WARN : self::BLOCK;
    }

    /**
     * The settings screen's Stock photos section, filled in: a row for each
     * paid library (whether its keys are set, never the keys, and Check
     * connection), a switch for each one set up, and the libraries "Search
     * in" can start on.
     *
     * @param  array<string, mixed>  $contents
     * @return array<string, mixed>
     */
    public function withStock(array $contents, Stock\StockLibraries $libraries): array
    {
        $rows = collect($libraries->rows())->map(function (array $row) {
            $keys = collect($row['keys'])->map(fn (bool $set, string $variable) => '<code style="font-size:.8rem">'.e($variable).'</code> '.self::pill($set ? __('Set') : __('Not set'), $set))->implode(' ');
            $status = match (true) {
                $row['demo'] => __('Charges nothing and calls nobody. Only on local and test sites, never in production.'),
                ! $row['ready'] => __('Coming: a later version of Ghostwriter adds this library. Its keys can be set now.'),
                in_array(false, $row['keys'], true) => __('Set both in .env to use it.'),
                default => null,
            };
            $check = $row['demo'] || ($row['ready'] && ! in_array(false, $row['keys'], true))
                ? '<button type="button" data-ghostwriter-check-connection="'.e($row['id']).'" style="font-size:.8rem;padding:.15rem .6rem;border:1px solid currentColor;border-radius:.375rem;opacity:.85">'.e(__('Check connection')).'</button>'
                : '';

            // Connect account / Disconnect, for a library that licenses for a
            // person's signed-in account.
            $connect = '';

            if ($row['connect'] !== null) {
                $c = $row['connect'];
                $button = 'font-size:.8rem;padding:.15rem .6rem;border:1px solid currentColor;border-radius:.375rem;opacity:.85';
                $connect = '<div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;font-size:.8rem">'
                    .self::pill($c['connected'] ? __('Account connected') : __('Account not connected'), $c['connected'])
                    .($c['connected']
                        ? '<button type="button" data-ghostwriter-disconnect="'.e($c['disconnect_url']).'" data-ghostwriter-library="'.e($row['label']).'" style="'.$button.'">'.e(__('Disconnect')).'</button>'
                        : '<a href="'.e($c['connect_url']).'" style="'.$button.';text-decoration:none">'.e(__('Connect account')).'</a>')
                    .'</div>'
                    .($row['demo'] ? '' : '<div style="font-size:.8rem;opacity:.75">'.e(__('Licensing needs your account connected. In your app\'s settings with the library, add this callback:')).' <code>'.e($c['callback']).'</code></div>');
            }

            return '<li style="margin:.6rem 0;display:flex;flex-direction:column;gap:.3rem">'
                .'<div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center"><strong>'.e($row['label']).'</strong>'.$keys.' '.$check.'</div>'
                .($status ? '<div style="font-size:.8rem;opacity:.75">'.e($status).'</div>' : '')
                .$connect
                .'<div data-ghostwriter-connection="'.e($row['id']).'" style="font-size:.8rem" role="status"></div>'
                .'</li>';
        })->implode('');

        $switches = collect($libraries->rows())
            ->filter(fn (array $row) => $row['demo'] || ($row['ready'] && ! in_array(false, $row['keys'], true)))
            ->map(fn (array $row) => ['handle' => 'stock_'.$row['id'], 'field' => [
                'type' => 'toggle',
                'display' => __('Use :library', ['library' => $row['label']]),
                'instructions' => __('Offer it in the image dialog\'s "Search in".'),
                'default' => true,
                'width' => 50,
            ]])
            ->values()
            ->all();

        $sources = ['free' => __('Free libraries')];

        foreach ($libraries->configured() as $id => $library) {
            $sources[$id] = $library->label();
        }

        $sources['everything'] = __('Everything');

        foreach ($contents['tabs'] ?? [] as $tab => $content) {
            foreach ($content['sections'] ?? [] as $i => $section) {
                if (($section['display'] ?? null) !== 'Stock photos') {
                    continue;
                }

                foreach ($section['fields'] ?? [] as $j => $field) {
                    if (($field['handle'] ?? null) === 'stock_default_source') {
                        $contents['tabs'][$tab]['sections'][$i]['fields'][$j]['field']['options'] = $sources;
                    }
                }

                array_unshift($contents['tabs'][$tab]['sections'][$i]['fields'], [
                    'handle' => 'stock_libraries',
                    'field' => ['type' => 'html', 'html' => '<ul style="list-style:none;margin:0;padding:0">'.$rows.'</ul>', 'hide_display' => true],
                ], ...$switches);
            }
        }

        return $contents;
    }

    private static function pill(string $text, bool $good): string
    {
        return '<span style="font-size:.75rem;line-height:1.1rem;padding:0 .4rem;border:1px solid currentColor;border-radius:.25rem;color:'.($good ? '#16a34a' : '#6b7280').'">'.e($text).'</span>';
    }

    /**
     * Each API key Ghostwriter can use, by the variable that holds it, and
     * whether it is set. Never the key itself.
     *
     * @return array<string, bool>
     */
    public function keyStatus(): array
    {
        $credentials = new ConfigCredentials;

        return collect(Credentials::ENV)->mapWithKeys(fn (string $variable, string $service) => [$variable => $credentials->key($service) !== null])->all();
    }

    /**
     * The settings screen's blueprint with a read-only list of the API keys
     * and whether each is set, in the API keys section above the AI provider.
     *
     * @param  array<string, mixed>  $contents
     * @return array<string, mixed>
     */
    public function withKeyStatus(array $contents): array
    {
        $rows = collect($this->keyStatus())
            ->map(fn (bool $set, string $variable) => '<li style="display:flex;gap:.5rem;align-items:center;margin:.2rem 0"><code style="font-size:.8rem">'.e($variable).'</code>'.self::pill($set ? 'Set' : 'Not set', $set).'</li>')
            ->implode('');

        foreach ($contents['tabs'] ?? [] as $tab => $content) {
            foreach ($content['sections'] ?? [] as $i => $section) {
                if (($section['display'] ?? null) === 'API keys') {
                    $contents['tabs'][$tab]['sections'][$i]['fields'] = [[
                        'handle' => 'key_status',
                        'field' => ['type' => 'html', 'html' => '<ul style="list-style:none;margin:0;padding:0">'.$rows.'</ul>', 'hide_display' => true],
                    ]];
                }
            }
        }

        return $contents;
    }

    /**
     * Whether collections are checked for kinds of content by themselves.
     */
    public function suggestsKinds(): bool
    {
        return (bool) ($this->value('suggest_kinds') ?? true);
    }

    /**
     * Collections Ghostwriter writes for. Empty means all of them.
     *
     * @return array<int, string>
     */
    public function collections(): array
    {
        return $this->handles($this->value('collections') ?? []);
    }

    /**
     * Collections read for the voice guide. Empty means all of them.
     *
     * @return array<int, string>
     */
    public function voiceCollections(): array
    {
        return $this->handles($this->value('voice_collections') ?? []);
    }

    /**
     * The value config/ghostwriter.php (or .env) gives a setting, or null
     * when it leaves it to the settings screen. Blank and empty count as
     * not set.
     */
    public function fromConfig(string $key): mixed
    {
        $value = config(self::IN_CONFIG[$key] ?? '');

        return $value === null || $value === '' || $value === [] ? null : $value;
    }

    /**
     * Each setting config/ghostwriter.php (or .env) fixes, at the value it
     * fixes it to, keyed by its field on the settings screen.
     *
     * @return array<string, mixed>
     */
    public function configured(): array
    {
        return collect(array_keys(self::IN_CONFIG))
            ->mapWithKeys(fn (string $key) => [$key => $this->fromConfig($key)])
            ->reject(fn (mixed $value) => $value === null)
            ->all();
    }

    /**
     * Whether a setting is fixed in code, so the settings screen cannot change it.
     */
    public function isOverridden(string $key): bool
    {
        return $this->fromConfig($key) !== null;
    }

    /**
     * The settings screen's blueprint with each field set in code locked,
     * and a note saying where it is set and what it is. The field shows that
     * value too: ConfiguredSettingsRepository lays it over the saved one.
     *
     * @param  array<string, mixed>  $contents
     * @return array<string, mixed>
     */
    public function lockOverridden(array $contents): array
    {
        foreach ($contents['tabs'] ?? [] as $tab => $content) {
            foreach ($content['sections'] ?? [] as $i => $section) {
                foreach ($section['fields'] ?? [] as $j => $field) {
                    $key = $field['handle'] ?? null;

                    if (! is_string($key) || ! $this->isOverridden($key)) {
                        continue;
                    }

                    $contents['tabs'][$tab]['sections'][$i]['fields'][$j]['field']['visibility'] = 'read_only';
                    $contents['tabs'][$tab]['sections'][$i]['fields'][$j]['field']['instructions'] = 'Set in config/ghostwriter.php (or .env) to '.$this->describe($this->fromConfig($key)).', which wins over this screen. Change it there.';
                }
            }
        }

        return $contents;
    }

    public function url(): ?string
    {
        return Addon::get(self::ADDON)?->settingsUrl();
    }

    /**
     * Whether the signed-in user may change these settings.
     */
    public function canChange(): bool
    {
        return (bool) User::current()?->can('edit '.self::ADDON.' settings');
    }

    /**
     * The settings screen's address, for those who may change the settings;
     * null for everyone else, so nobody is sent to a screen they cannot open.
     */
    public function urlForCurrentUser(): ?string
    {
        return $this->canChange() ? $this->url() : null;
    }

    /**
     * A setting as it applies: from the config when it is set there, from
     * the settings screen otherwise. Null when neither sets it.
     */
    private function value(string $key): mixed
    {
        if (($configured = $this->fromConfig($key)) !== null) {
            return $configured;
        }

        $raw = Addon::get(self::ADDON)?->settings()->raw() ?? [];
        $saved = $raw[$key] ?? null;

        return $saved === '' || $saved === [] ? null : $saved;
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'on' : 'off',
            is_array($value) => '"'.implode(', ', array_map('strval', $value)).'"',
            default => '"'.$value.'"',
        };
    }

    /**
     * @return array<int, string>
     */
    private function handles(mixed $value): array
    {
        return array_values(array_filter(array_map('strval', (array) $value)));
    }
}
