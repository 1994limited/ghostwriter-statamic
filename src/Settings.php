<?php

namespace NineteenNinetyFour\Ghostwriter;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\ConnectsProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Models;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Connections;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Strings;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\OnPublish;
use Statamic\Facades\Addon;
use Statamic\Facades\User;

/**
 * Where Ghostwriter's options come from: config/ghostwriter.php (and so
 * .env) first, then the addon's settings screen in the Control Panel.
 * Keys aren't settings: they are set up in Settings → Connections (or in
 * .env, which wins), through core's Connections. A
 * value set in code wins, so a site can fix one per environment; the
 * settings screen shows that field locked, with a note saying so.
 */
class Settings
{
    public const ADDON = '1994/ghostwriter-statamic';

    /** Ghostwriter's mark as the pack's 14-grid stroke icon, in currentColor. */
    public const ICON = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path d="M2.5 12.5V6a4.5 4.5 0 0 1 9 0v4l-2.5 2.5Z" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.5 10H9v2.5" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"></path><circle cx="5.4" cy="6.25" r=".75" fill="currentColor"></circle><circle cx="8.6" cy="6.25" r=".75" fill="currentColor"></circle></svg>';

    /** Providers Ghostwriter makes images with. */
    public const IMAGE_PROVIDERS = ['openai', 'gemini', 'openrouter'];

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
        'on_unfinished_publish' => 'ghostwriter.publish.on_unfinished',
        'stock_default_source' => 'ghostwriter.stock.default_source',
        'stock_include_editorial' => 'ghostwriter.stock.include_editorial',
        'openrouter_writing_model' => 'ghostwriter.openrouter.models.writing',
        'openrouter_quick_model' => 'ghostwriter.openrouter.models.quick',
        'revisit_external_links' => 'ghostwriter.revisit.external_links',
        'revisit_age_in_full' => 'ghostwriter.revisit.age_in_full',
        'suggest_claims' => 'ghostwriter.suggest.claims',
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

    /**
     * The OpenRouter model chosen for a tier ("writing" or "quick"), or null
     * for the default.
     */
    public function openRouterModel(string $tier): ?string
    {
        $model = in_array($tier, ['writing', 'quick'], true) ? $this->value("openrouter_{$tier}_model") : null;

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
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
     * default), or warn and let it through. The same as
     * onUnfinishedPublish(), which it was before Finish this page.
     */
    public function stockOnPublish(): string
    {
        return $this->onUnfinishedPublish() === OnPublish::Warn ? self::WARN : self::BLOCK;
    }

    /**
     * When a page with something still to finish (a fact to add, a link to
     * choose, an image placeholder, an unlicensed stock preview) is
     * published: block (the default), or warn and let it through. The
     * stock photos setting it replaced is read when it isn't set.
     */
    public function onUnfinishedPublish(): OnPublish
    {
        return OnPublish::fromConfig($this->value('on_unfinished_publish'), $this->value('stock_on_publish'));
    }

    /**
     * Whether the Finish this page guide opens by itself once a draft is
     * put into the form, whatever the person last left it as.
     */
    public function openGuideAfterDraft(): bool
    {
        return (bool) config('ghostwriter.finish.open_after_draft', true);
    }

    /**
     * The settings screen's Stock photos section, filled in: a row for each
     * paid library (where it stands in Connections, never a key, and Check
     * connection), a switch for each one set up, and the libraries "Search
     * in" can start on. Keys and Connect account are in Settings → Connections.
     *
     * @param  array<string, mixed>  $contents
     * @return array<string, mixed>
     */
    public function withStock(array $contents, Stock\StockLibraries $libraries): array
    {
        $connections = app(Connections::class);
        $strings = Strings::for(app()->getLocale());
        $rows = collect($libraries->rows())->map(function (array $row) use ($connections, $strings) {
            $known = $connections->services()->get($row['id']);
            $status = $known ? $connections->status($row['id']) : null;
            $pill = $status ? self::pill($status->label($strings), $status->usable() && ! $status->broken) : '';
            $setUp = $row['ready'] && ! $row['demo'] ? '<a href="'.e(cp_route('ghostwriter.connections.show')).'" style="font-size:.8rem;text-decoration:underline">'.e($strings->get('elsewhere.link')).'</a>' : '';
            $line = match (true) {
                $row['demo'] => __('Charges nothing and calls nobody. Only on local and test sites, never in production.'),
                ! $row['ready'] => __('Coming: a later version of Ghostwriter adds this library. Its keys can be set now.'),
                default => null,
            };
            $check = $row['demo'] || ($row['ready'] && ! in_array(false, $row['keys'], true))
                ? '<button type="button" data-ghostwriter-check-connection="'.e($row['id']).'" style="font-size:.8rem;padding:.15rem .6rem;border:1px solid currentColor;border-radius:.375rem;opacity:.85">'.e(__('Check connection')).'</button>'
                : '';

            return '<li style="margin:.6rem 0;display:flex;flex-direction:column;gap:.3rem">'
                .'<div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center"><strong>'.e($row['label']).'</strong>'.$pill.' '.$check.' '.$setUp.'</div>'
                .($line ? '<div style="font-size:.8rem;opacity:.75">'.e($line).'</div>' : '')
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

    /**
     * The AI provider section with the models offered for each OpenRouter
     * tier. Connecting OpenRouter is on its card in Settings → Connections.
     *
     * @param  array<string, mixed>  $contents
     * @return array<string, mixed>
     */
    public function withOpenRouter(array $contents, ConnectsProvider $connection): array
    {
        $choices = Models::OPENROUTER_TEXT_CHOICES;

        foreach ($contents['tabs'] ?? [] as $tab => $content) {
            foreach ($content['sections'] ?? [] as $i => $section) {
                if (($section['display'] ?? null) !== 'AI provider') {
                    continue;
                }

                foreach ($section['fields'] ?? [] as $j => $field) {
                    if (in_array($field['handle'] ?? null, ['openrouter_writing_model', 'openrouter_quick_model'], true)) {
                        $contents['tabs'][$tab]['sections'][$i]['fields'][$j]['field']['options'] = $choices;
                    }
                }
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
        $credentials = app(Connections::class);

        return collect(Credentials::ENV)->mapWithKeys(fn (string $variable, string $service) => [$variable => $credentials->key($service) !== null])->all();
    }

    /**
     * The settings screen's Connections section: each service and where it
     * stands (never a key, only its last four characters), and the way to
     * Settings → Connections, where keys are set up.
     *
     * @param  array<string, mixed>  $contents
     * @return array<string, mixed>
     */
    public function withKeyStatus(array $contents): array
    {
        $connections = app(Connections::class);
        $strings = Strings::for(app()->getLocale());
        $rows = collect($connections->services()->list())
            ->map(function ($service) use ($connections, $strings) {
                $status = $connections->status($service->id);

                return '<li style="display:flex;gap:.5rem;align-items:center;margin:.2rem 0"><span>'.e($service->name).'</span>'.self::pill($status->label($strings), $status->usable() && ! $status->broken).'</li>';
            })
            ->implode('');
        $link = '<a href="'.e(cp_route('ghostwriter.connections.show')).'" style="display:inline-block;margin-top:.5rem;font-size:.8rem;padding:.15rem .6rem;border:1px solid currentColor;border-radius:.375rem;text-decoration:none">'.e($strings->get('elsewhere.link')).'</a>';

        foreach ($contents['tabs'] ?? [] as $tab => $content) {
            foreach ($content['sections'] ?? [] as $i => $section) {
                if (($section['display'] ?? null) === 'Connections') {
                    $contents['tabs'][$tab]['sections'][$i]['instructions'] = $strings->get('elsewhere.keys');
                    $contents['tabs'][$tab]['sections'][$i]['fields'] = [[
                        'handle' => 'key_status',
                        'field' => ['type' => 'html', 'html' => '<ul style="list-style:none;margin:0;padding:0">'.$rows.'</ul>'.$link, 'hide_display' => true],
                    ]];
                }
            }
        }

        return $contents;
    }

    /**
     * Whether collections are checked for kinds of content by themselves.
     */
    /**
     * Content to revisit: whether links to other sites are checked once a
     * week. Off unless a manager turns it on; links to the site's own
     * entries are always checked, with no request.
     */
    public function checksExternalLinks(): bool
    {
        return filter_var($this->value('revisit_external_links') ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * Collections with a date field where age counts in full, as in any
     * other: a manager's switch per collection. Everywhere else with a
     * date field (a journal, news), age and past years weigh a quarter.
     *
     * @return array<int, string>
     */
    public function ageInFull(): array
    {
        return $this->handles($this->value('revisit_age_in_full') ?? []);
    }

    /**
     * Suggest edits: whether claims and counts about the organisation
     * ("team of 6", "award-winning") are flagged as facts to check. On
     * unless switched off.
     */
    public function checksClaims(): bool
    {
        return filter_var($this->value('suggest_claims') ?? true, FILTER_VALIDATE_BOOL);
    }

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
