<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\ConnectsAccount;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use Statamic\Facades\CP\Toast;

/**
 * "Connect account" for a library that licenses only for a person's own
 * signed-in account (Shutterstock), following core's
 * docs/connecting-accounts.md: a random, single-use `state` kept in the
 * Control Panel session and checked with hash_equals() before connect() is
 * called with it; one absolute callback address in both halves; tokens
 * kept by the library in the site's encrypted LibraryTokens. Managers
 * only, on all three routes.
 */
class LibraryConnectionController
{
    private const SESSION = 'ghostwriter.oauth.';

    public function __construct(private StockLibraries $libraries, private Settings $settings) {}

    public function connect(Request $request, string $library): RedirectResponse
    {
        $found = $this->library($library);
        $state = bin2hex(random_bytes(16));
        $request->session()->put(self::SESSION.$library, $state);

        return redirect()->away($found->authorizationUrl($state, StockLibraries::callbackUrl($library)));
    }

    public function callback(Request $request, string $library): RedirectResponse
    {
        $found = $this->library($library);
        $expected = $request->session()->pull(self::SESSION.$library);

        abort_unless(is_string($expected) && hash_equals($expected, (string) $request->query('state')), 403, __('That sign-in link has expired or was already used. Try Connect account again.'));

        if ($request->query('error')) {
            Toast::error(__(':library isn\'t connected: the sign-in was cancelled.', ['library' => $found->label()]));

            return $this->back();
        }

        try {
            $found->connect((string) $request->query('code'), StockLibraries::callbackUrl($library), $expected);
        } catch (NotConnected $refused) {
            Toast::error($refused->getMessage())->duration(12000);

            return $this->back();
        }

        Toast::success(__(':library is connected. Licences will come from that account.', ['library' => $found->label()]));

        return $this->back();
    }

    public function disconnect(string $library): JsonResponse
    {
        $found = $this->library($library);
        $found->disconnect();

        return response()->json(['message' => __(':library is disconnected.', ['library' => $found->label()])]);
    }

    private function library(string $id): ConnectsAccount
    {
        abort_unless($this->settings->canChange(), 403);

        $library = $this->libraries->configured()[$id] ?? null;

        return $library instanceof ConnectsAccount ? $library : abort(404);
    }

    private function back(): RedirectResponse
    {
        return redirect($this->settings->url() ?? cp_route('ghostwriter.index'));
    }
}
