<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Connections\ConnectionsPage;
use NineteenNinetyFour\Ghostwriter\Core\Connections\ChecksKeys;
use NineteenNinetyFour\Ghostwriter\Core\Connections\ConnectionRefused;
use NineteenNinetyFour\Ghostwriter\Settings;

/**
 * Settings → Connections: a card for every service Ghostwriter uses, each
 * set up by pasting a key that is checked live before it is kept
 * (encrypted). Only for those who may change Ghostwriter's settings.
 */
class ConnectionsController
{
    public function __construct(private ConnectionsPage $page, private Settings $settings) {}

    public function show(): Response
    {
        $this->authorize();

        return Inertia::render('ghostwriter::Connections', $this->page->payload());
    }

    public function status(): JsonResponse
    {
        $this->authorize();

        return response()->json($this->page->payload());
    }

    /**
     * Check & save: the pasted fields are checked with the service, and
     * kept only when it accepts them.
     */
    public function save(Request $request, string $service, ChecksKeys $check): JsonResponse
    {
        $this->authorize();

        $validated = $request->validate(['fields' => ['required', 'array'], 'fields.*' => ['nullable', 'string', 'max:2000']]);
        $connections = $this->page->connections();
        $found = $connections->services()->get($service) ?? abort(404);
        $strings = $this->page->strings();

        try {
            if ($connections->fromEnvironment($found)) {
                throw new ConnectionRefused('env-wins', ['service' => $found->name, 'variable' => $found->required()[0]->env]);
            }

            $result = $check->check($found, $validated['fields']);

            if (! $result->ok) {
                return response()->json(['message' => $result->message($strings)], 422);
            }

            $connections->save($service, $validated['fields']);
        } catch (ConnectionRefused $refused) {
            return response()->json(['message' => $refused->translated($strings)], 422);
        }

        return response()->json(['message' => $strings->get('saved', ['service' => $found->name]), 'card' => $this->page->card($found)]);
    }

    public function disconnect(string $service): JsonResponse
    {
        $this->authorize();

        $connections = $this->page->connections();
        $found = $connections->services()->get($service) ?? abort(404);
        $strings = $this->page->strings();

        try {
            $connections->forget($service);
        } catch (ConnectionRefused $refused) {
            return response()->json(['message' => $refused->translated($strings)], 409);
        }

        return response()->json(['message' => $strings->get('disconnected', ['service' => $found->name]), 'card' => $this->page->card($found)]);
    }

    private function authorize(): void
    {
        abort_unless($this->settings->canChange(), 403, $this->page->strings()->get('forbidden'));
    }
}
