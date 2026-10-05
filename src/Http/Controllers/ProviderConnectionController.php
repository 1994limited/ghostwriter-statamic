<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\ConnectsProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\Pkce;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Settings;
use Statamic\Facades\CP\Toast;

/**
 * "Connect with OpenRouter", following core's docs/connecting-accounts.md:
 * a random `state` and a PKCE verifier kept together in the Control Panel
 * session, single use, the state checked with hash_equals() before the
 * code is exchanged; one absolute https:// callback from the CP's own URL
 * helper; the key kept by core in the site's encrypted ProviderKeys. A key
 * in .env always wins. Managers only, on every route.
 */
class ProviderConnectionController
{
    private const SESSION = 'ghostwriter.connect.';

    public function __construct(private ConnectsProvider $connection, private Settings $settings) {}

    public function connect(Request $request, string $provider): RedirectResponse
    {
        $this->authorize($provider);

        $state = bin2hex(random_bytes(16));
        $verifier = Pkce::verifier();

        try {
            $url = $this->connection->authorizationUrl($state, self::callbackUrl($provider), Pkce::challenge($verifier));
        } catch (ProviderException $refused) {
            Toast::error($refused->getMessage())->duration(12000);

            return $this->back();
        }

        $request->session()->put(self::SESSION.$provider, ['state' => $state, 'verifier' => $verifier]);

        return redirect()->away($url);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->authorize($provider);

        $kept = $request->session()->pull(self::SESSION.$provider);

        abort_unless(
            is_array($kept) && is_string($kept['state'] ?? null) && is_string($kept['verifier'] ?? null) && hash_equals($kept['state'], (string) $request->query('state')),
            403,
            __('That sign-in link has expired or was already used. Try Connect with OpenRouter again.'),
        );

        if (! $request->query('code')) {
            Toast::error(__('OpenRouter isn\'t connected: the sign-in was cancelled.'));

            return $this->back();
        }

        try {
            $key = $this->connection->connect((string) $request->query('code'), $kept['verifier']);
        } catch (ProviderException $refused) {
            Toast::error($refused->getMessage())->duration(12000);

            return $this->back();
        }

        Toast::success(__('Connected to OpenRouter (:key).', ['key' => $key->masked()]));

        return $this->back();
    }

    public function disconnect(string $provider): JsonResponse
    {
        $this->authorize($provider);

        try {
            $this->connection->disconnect();
        } catch (ProviderException $refused) {
            abort(409, $refused->getMessage());
        }

        return response()->json(['message' => __('OpenRouter is disconnected. To revoke the key, delete it at openrouter.ai/settings/keys.')]);
    }

    /**
     * "Check connection": the key's credit, or what is wrong with it.
     */
    public function check(string $provider): JsonResponse
    {
        $this->authorize($provider);

        try {
            $account = $this->connection->account();
        } catch (ProviderException $failed) {
            return response()->json(['ok' => false, 'message' => $failed->getMessage()]);
        }

        return response()->json(['ok' => ! $account->exhausted(), 'message' => $account->summary()]);
    }

    /**
     * Where OpenRouter sends the person back to: absolute, from the site's
     * own address (never the request's Host header), the same in both
     * halves.
     */
    public static function callbackUrl(string $provider = 'openrouter'): string
    {
        return rtrim((string) config('app.url'), '/').route('statamic.cp.ghostwriter.providers.callback', ['provider' => $provider], false);
    }

    private function authorize(string $provider): void
    {
        abort_unless($this->settings->canChange(), 403);
        abort_unless($provider === $this->connection->provider(), 404);
    }

    private function back(): RedirectResponse
    {
        return redirect(cp_route('ghostwriter.connections.show'));
    }
}
