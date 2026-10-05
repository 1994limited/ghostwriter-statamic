<?php

namespace NineteenNinetyFour\Ghostwriter\Connections;

use NineteenNinetyFour\Ghostwriter\Ai\ConfigCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Connections;
use NineteenNinetyFour\Ghostwriter\Core\Connections\CredentialStore;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Service;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Services;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Status;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Strings;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\ConnectsAccount;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use NineteenNinetyFour\Ghostwriter\Testing\FakeScenarios;

/**
 * What the Connections page shows: each group of cards, each card's
 * status (never a key: only its last four characters), how to sign in
 * where a service can, the environment the site is on, and the words in
 * the editor's language.
 */
class ConnectionsPage
{
    public function __construct(private Connections $connections, private StockLibraries $libraries) {}

    /**
     * The resolver, with the test services while an end-to-end scenario
     * is playing on a local site.
     */
    public function connections(): Connections
    {
        if (! FakeScenarios::playing()) {
            return $this->connections;
        }

        return new Connections(new ConfigCredentials, app(CredentialStore::class), Services::all()->withTestServices());
    }

    public function strings(): Strings
    {
        return Strings::for(app()->getLocale());
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $strings = $this->strings();
        $groups = [];

        foreach ($this->connections()->services()->grouped() as $group => $services) {
            $groups[] = [
                'id' => $group,
                'title' => $strings->get("group.{$group}"),
                'intro' => $strings->get("group.{$group}.intro"),
                'cards' => array_map(fn (Service $service) => $this->card($service), $services),
            ];
        }

        $store = app(CredentialStore::class);
        $environment = (string) app()->environment();

        return [
            'groups' => $groups,
            'strings' => $strings->all(),
            'environment' => $strings->has("environment.{$environment}") ? $strings->get("environment.{$environment}") : $environment,
            'stored_in' => $store instanceof EncryptedCredentialStore ? $store->where() : 'database',
            'urls' => [
                'base' => cp_route('ghostwriter.connections.show'),
                'settings' => app(Settings::class)->url(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function card(Service $service): array
    {
        $strings = $this->strings();
        $status = $this->connections()->status($service->id);
        $variable = $service->required()[0]->env ?? '';

        return $service->toArray($strings) + [
            'status' => $status->toArray($strings),
            'help' => match (true) {
                $status->state === Status::ENV && $status->broken => $strings->get('status.env.broken', ['service' => $service->name, 'variable' => $variable]),
                $status->state === Status::ENV => $strings->get($status->where === 'config' ? 'status.config.help' : 'status.env.help', ['variable' => $variable]),
                $status->state === Status::BROKEN => $strings->get('status.broken.help', ['service' => $service->name]),
                $status->state === Status::NO_KEY => $strings->get('status.no-key.help'),
                $status->via === 'connect' => $strings->get('status.via-connect', ['service' => $service->name]),
                default => null,
            },
            'oauth_links' => $this->oauth($service, $status),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function oauth(Service $service, Status $status): ?array
    {
        if ($service->oauth === Service::OAUTH_KEY) {
            return $status->state === Status::ENV ? null : ['connect_url' => cp_route('ghostwriter.providers.connect', $service->id)];
        }

        if ($service->oauth !== Service::OAUTH_ACCOUNT) {
            return null;
        }

        $library = $this->libraries->configured()[$service->id] ?? null;

        if (! $library instanceof ConnectsAccount) {
            return ['needs_key' => true];
        }

        return [
            'needs_key' => false,
            'connected' => $library->connected(),
            'connect_url' => cp_route('ghostwriter.libraries.connect', $service->id),
            'disconnect_url' => cp_route('ghostwriter.libraries.disconnect', $service->id),
            'callback' => (string) preg_replace('#^https?://#', '', StockLibraries::callbackUrl($service->id)),
        ];
    }
}
