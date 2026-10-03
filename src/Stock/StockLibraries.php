<?php

namespace NineteenNinetyFour\Ghostwriter\Stock;

use Illuminate\Support\Facades\Log;
use NineteenNinetyFour\Ghostwriter\Ai\ConfigCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Account;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\ConnectsAccount;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Paid\Shutterstock;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Ports\LibraryTokens;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Settings;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Throwable;

/**
 * The photo libraries this site can search, built from config:
 *
 * - the free ones, as they always were (core's StockSearch);
 * - each paid library whose keys are set in .env and whose adapter core
 *   has, and which isn't switched off on the settings screen;
 * - the demo library, on a local site or where config turns it on, and
 *   never in production.
 *
 * It also answers what "Search in" offers a person, where it starts for
 * them, and gives the search for the choice they made.
 */
class StockLibraries
{
    /** "Search in": every enabled free library, merged. */
    public const FREE = 'free';

    /** "Search in": free and paid together, each library's best taking turns. */
    public const EVERYTHING = 'everything';

    /** The person's last "Search in" choice, as a Statamic user preference. */
    public const PREFERENCE = 'ghostwriter.stock_source';

    /**
     * The paid libraries Ghostwriter knows of: their name, the core adapter
     * that makes them work, and the .env variables their key and secret
     * are read from. An adapter core doesn't have yet leaves its library
     * inert: its keys show on the settings screen, and nothing else.
     */
    public const PAID = [
        'getty' => ['label' => 'Getty Images and iStock', 'adapter' => 'NineteenNinetyFour\\Ghostwriter\\Core\\Images\\Libraries\\Paid\\Getty', 'keys' => ['getty' => 'GETTY_API_KEY', 'getty_secret' => 'GETTY_API_SECRET']],
        'shutterstock' => ['label' => 'Shutterstock', 'adapter' => 'NineteenNinetyFour\\Ghostwriter\\Core\\Images\\Libraries\\Paid\\Shutterstock', 'keys' => ['shutterstock' => 'SHUTTERSTOCK_API_KEY', 'shutterstock_secret' => 'SHUTTERSTOCK_API_SECRET']],
    ];

    /** Short names for the source chip on a result card. */
    private const SHORT = ['demo' => 'Demo', 'getty' => 'Getty', 'istock' => 'iStock', 'shutterstock' => 'Shutterstock', 'adobe' => 'Adobe Stock', 'alamy' => 'Alamy'];

    /** @var array<string, LicensableLibrary>|null */
    private ?array $paid = null;

    public function __construct(private StockSearch $free, private Settings $settings, private HttpClients $http) {}

    /**
     * The free libraries, as searched today.
     */
    public function free(): StockSearch
    {
        return $this->free;
    }

    /**
     * Whether the demo library may be offered: never in production; on a
     * local site unless config turns it off; elsewhere only when config
     * turns it on.
     */
    public static function demoAllowed(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        $configured = config('ghostwriter.stock.demo');

        return $configured === null || $configured === '' ? app()->environment('local') : filter_var($configured, FILTER_VALIDATE_BOOL);
    }

    /**
     * Every paid library that is set up (keys in .env and an adapter, or
     * the demo where allowed), switched on or not, by ID.
     *
     * @return array<string, LicensableLibrary>
     */
    public function configured(): array
    {
        if ($this->paid !== null) {
            return $this->paid;
        }

        $paid = [];

        if (self::demoAllowed()) {
            // A test can bind its own scripted library in its place.
            $paid[DemoLibrary::ID] = app()->bound(DemoLibrary::BINDING) ? app(DemoLibrary::BINDING) : DemoLibrary::make();
        }

        foreach (self::PAID as $id => $library) {
            if (! $this->keysSet($id) || ! class_exists($library['adapter'])) {
                continue;
            }

            // Libraries that license for a person's signed-in account
            // (Shutterstock) implement core's ConnectsAccount: "Connect
            // account" on their settings row, tokens kept encrypted by
            // EncryptedLibraryTokens (docs: core's connecting-accounts.md).
            try {
                $made = $this->make($id, $library['adapter']);

                if ($made instanceof LicensableLibrary) {
                    $paid[$made->id()] = $made;
                }
            } catch (Throwable $exception) {
                Log::channel(config('ghostwriter.log_channel'))->warning("Ghostwriter: the {$library['label']} library could not be set up: {$exception->getMessage()}");
            }
        }

        return $this->paid = $paid;
    }

    /**
     * The paid libraries switched on and ready to search, by ID.
     *
     * @return array<string, LicensableLibrary>
     */
    public function paid(): array
    {
        return array_filter($this->configured(), fn (LicensableLibrary $library, string $id) => $this->enabled($id) && $library->available(), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * A paid library by ID, if it is switched on and ready.
     */
    public function licensable(string $id): ?LicensableLibrary
    {
        return $this->paid()[$id] ?? null;
    }

    /**
     * Any library by ID: a free one, or a paid one set up (switched on or
     * not, since a preview already in a page can still be licensed only
     * when it is on, but its record still names it).
     */
    public function library(string $id): ?PhotoLibrary
    {
        return $this->configured()[$id] ?? $this->free->library($id);
    }

    public function isPaid(string $id): bool
    {
        return isset($this->configured()[$id]) || isset(self::PAID[$id]);
    }

    /** Whether a paid library is switched on (it is, unless switched off on the settings screen). */
    public function enabled(string $id): bool
    {
        return $this->settings->stockLibraryEnabled($id);
    }

    public function label(string $id): string
    {
        return ($this->configured()[$id] ?? null)?->label() ?? (self::PAID[$id]['label'] ?? $this->free->label($id));
    }

    /** "Getty", "iStock", "Unsplash": for the chip on a result card. */
    public function shortLabel(string $id): string
    {
        return self::SHORT[$id] ?? $this->free->label($id);
    }

    /**
     * What "Search in" offers: the free libraries, each paid library on and
     * ready, and everything. Managers also see a paid library that is set
     * up but can't be searched, marked to connect in Settings.
     *
     * `label` is for the select ("Demo stock (no charge)"); `short` for running
     * text ("Demo").
     *
     * @return array<int, array{value: string, label: string, short?: string, paid: bool, disabled?: bool}>
     */
    public function choices(bool $manager = false): array
    {
        $choices = [];

        if ($this->free->sources() !== []) {
            $choices[] = ['value' => self::FREE, 'label' => __('Free libraries'), 'paid' => false];
        }

        foreach ($this->configured() as $id => $library) {
            if (! $this->enabled($id)) {
                continue;
            }

            if ($library->available()) {
                $choices[] = ['value' => $id, 'label' => $library->label(), 'short' => $this->shortLabel($id), 'paid' => true];
            } elseif ($manager) {
                $choices[] = ['value' => $id, 'label' => __(':library (connect in Settings)', ['library' => $library->label()]), 'short' => $this->shortLabel($id), 'paid' => true, 'disabled' => true];
            }
        }

        if ($this->paid() !== [] && $this->free->sources() !== []) {
            $choices[] = ['value' => self::EVERYTHING, 'label' => __('Everything'), 'paid' => true];
        }

        return $choices;
    }

    /**
     * Where "Search in" starts for this person: their last choice, else the
     * site's default source, else the free libraries; whichever of those is
     * on offer.
     */
    public function startingSource(?UserContract $user = null): string
    {
        $user ??= User::current();
        $offered = array_column(array_filter($this->choices(), fn (array $choice) => empty($choice['disabled'])), 'value');

        foreach ([$user?->getPreference(self::PREFERENCE), $this->settings->stockDefaultSource(), self::FREE] as $source) {
            if (is_string($source) && in_array($source, $offered, true)) {
                return $source;
            }
        }

        return $offered[0] ?? self::FREE;
    }

    /**
     * Remembers a person's "Search in" choice for next time.
     */
    public function remember(string $source, ?UserContract $user = null): void
    {
        $user ??= User::current();

        if ($user && $user->getPreference(self::PREFERENCE) !== $source) {
            $user->setPreference(self::PREFERENCE, $source)->save();
        }
    }

    /**
     * The library IDs a "Search in" choice covers, of those on offer.
     *
     * @return array<int, string>
     */
    public function scope(string $source): array
    {
        return match (true) {
            $source === self::FREE => $this->free->sources(),
            $source === self::EVERYTHING => [...$this->free->sources(), ...array_keys($this->paid())],
            isset($this->paid()[$source]) => [$source],
            default => [],
        };
    }

    /**
     * The search for a "Search in" choice.
     */
    public function search(string $source, bool $editorial = false): StockSearch
    {
        $logger = Log::channel(config('ghostwriter.log_channel'));

        return new ScopedStockSearch(
            $this->http,
            new ConfigCredentials,
            fn (): bool => $this->settings->openverse(),
            $logger,
            array_values($this->paid()),
            $this->scope($source),
            $editorial,
            $source === self::EVERYTHING,
            $logger,
        );
    }

    /**
     * "Check connection": who the library is connected as and what the
     * account can still buy, in words. Never a key.
     *
     * @return array{ok: bool, account: ?string, products: array<int, string>, message: ?string}
     */
    public function check(string $id): array
    {
        $library = $this->configured()[$id] ?? null;

        if ($library === null) {
            return ['ok' => false, 'account' => null, 'products' => [], 'message' => __('This library isn\'t set up: its keys aren\'t in .env, or this version of Ghostwriter can\'t use it yet.')];
        }

        try {
            $account = $library->account();
        } catch (Throwable $exception) {
            return ['ok' => false, 'account' => null, 'products' => [], 'message' => $exception->getMessage()];
        }

        return ['ok' => true, 'account' => $account->name, 'products' => self::products($account), 'message' => null];
    }

    /**
     * Each product on an account in words: "Demo pack: 100 downloads left".
     *
     * @return array<int, string>
     */
    public static function products(Account $account): array
    {
        return array_map(function (array $product) {
            $line = $product['name'];

            if ($product['remaining'] !== null) {
                $line .= ': '.__(':count left', ['count' => $product['remaining']->label()]);
            }

            if ($product['resetsAt'] !== null) {
                $line .= ', '.__('resets :date', ['date' => $product['resetsAt']->format('j M')]);
            }

            if ($product['termEndsAt'] !== null) {
                $line .= ', '.__('ends :date', ['date' => $product['termEndsAt']->format('j M Y')]);
            }

            return $line;
        }, $account->products);
    }

    /**
     * The settings screen's row for each paid library: its name, whether
     * its keys are set (never the keys), and whether Ghostwriter can use it
     * yet. It never reads the saved settings: the settings screen's own
     * blueprint is built from it, and reading them would ask for that
     * blueprint again.
     *
     * @return array<int, array{id: string, label: string, keys: array<string, bool>, ready: bool, demo: bool, connect: ?array<string, mixed>}>
     */
    public function rows(): array
    {
        $rows = [];

        if (self::demoAllowed()) {
            $rows[] = ['id' => DemoLibrary::ID, 'label' => 'Demo stock (no charge)', 'keys' => [], 'ready' => true, 'demo' => true, 'connect' => $this->connection(DemoLibrary::ID)];
        }

        $credentials = new ConfigCredentials;

        foreach (self::PAID as $id => $library) {
            $rows[] = [
                'id' => $id,
                'label' => $library['label'],
                'keys' => array_combine(array_values($library['keys']), array_map(fn (string $service) => $credentials->key($service) !== null, array_keys($library['keys']))),
                'ready' => class_exists($library['adapter']),
                'demo' => false,
                'connect' => $this->connection($id),
            ];
        }

        return $rows;
    }

    /**
     * For a library that licenses for a signed-in account: whether it is
     * connected, where Connect account and Disconnect go, and the callback
     * to register with the provider (Shutterstock wants the host name and
     * path, not the full address).
     *
     * @return array{connected: bool, connect_url: string, disconnect_url: string, callback: string}|null
     */
    private function connection(string $id): ?array
    {
        $library = $this->configured()[$id] ?? null;

        if (! $library instanceof ConnectsAccount || ! $library->capabilities()->needsOAuth) {
            return null;
        }

        $callback = self::callbackUrl($id);

        return [
            'connected' => $library->connected(),
            'connect_url' => cp_route('ghostwriter.libraries.connect', $id),
            'disconnect_url' => cp_route('ghostwriter.libraries.disconnect', $id),
            'callback' => (string) preg_replace('#^https?://#', '', $callback),
        ];
    }

    /**
     * One paid library's adapter, with the site's keys and token store.
     */
    private function make(string $id, string $adapter): object
    {
        $credentials = new ConfigCredentials;

        return match ($id) {
            'shutterstock' => new Shutterstock(
                $this->http,
                (string) $credentials->key('shutterstock'),
                (string) $credentials->key('shutterstock_secret'),
                app(LibraryTokens::class),
                sandbox: self::shutterstockSandbox(),
                // Editorial results come back, and "Search in" leaves them
                // out unless "Include editorial images" is ticked.
                editorial: true,
            ),
            default => app()->make($adapter),
        };
    }

    /**
     * Whether Shutterstock calls go to its sandbox (licensing charges
     * nothing there): config, else on a local site.
     */
    public static function shutterstockSandbox(): bool
    {
        $configured = config('ghostwriter.stock.shutterstock_sandbox');

        return $configured === null || $configured === '' ? app()->environment('local') : filter_var($configured, FILTER_VALIDATE_BOOL);
    }

    /**
     * The address a library sends the person back to after signing in:
     * absolute, from the site's own URL (never the request's Host header),
     * and the same in both halves of the sign-in.
     */
    public static function callbackUrl(string $id): string
    {
        return rtrim((string) config('app.url'), '/').route('statamic.cp.ghostwriter.libraries.callback', ['library' => $id], false);
    }

    private function keysSet(string $id): bool
    {
        $credentials = new ConfigCredentials;

        foreach (array_keys(self::PAID[$id]['keys']) as $service) {
            if ($credentials->key($service) === null) {
                return false;
            }
        }

        return true;
    }
}
