#!/usr/bin/env php
<?php

/**
 * Marketplace screenshots, taken from the real product in a local site
 * with headless Chrome over the DevTools protocol. No dependencies: a
 * small WebSocket client is below.
 *
 * Usage:
 *   GW_SHOT_URL=https://1994.test GW_SHOT_EMAIL=… GW_SHOT_PASSWORD=… \
 *   GW_SHOT_SESSION=<a session id with a draft> \
 *   php scripts/screenshots.php [output-dir]
 *
 * It signs in as that user, visits each screen, and saves PNGs at 1600×900
 * and 3200×1800 (device pixel ratio 2) to docs/store/raw/. Set them in the
 * brand frames with scripts/frame.php.
 *
 * Needs Google Chrome (GW_SHOT_CHROME to point at the binary) and a user
 * who may use Ghostwriter. Make a throwaway user for it and delete it after.
 */
require __DIR__.'/lib/DevToolsSocket.php';
require __DIR__.'/lib/Chrome.php';

$url = rtrim((string) getenv('GW_SHOT_URL'), '/');
$email = (string) getenv('GW_SHOT_EMAIL');
$password = (string) getenv('GW_SHOT_PASSWORD');
$session = (string) getenv('GW_SHOT_SESSION');
$chrome = getenv('GW_SHOT_CHROME') ?: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
$out = $argv[1] ?? __DIR__.'/../docs/store/raw';

if ($url === '' || $email === '' || $password === '') {
    fwrite(STDERR, "Set GW_SHOT_URL, GW_SHOT_EMAIL and GW_SHOT_PASSWORD.\n");
    exit(1);
}

if (! is_file($chrome)) {
    fwrite(STDERR, "Chrome not found at {$chrome}; set GW_SHOT_CHROME.\n");
    exit(1);
}

@mkdir($out, 0777, true);

/**
 * The screens, in Marketplace order. `after` is JavaScript run once the
 * page has loaded, before the shot, to open or settle things; `wait` is
 * extra seconds for animations.
 */
$shots = [
    '01-writing-panel' => [
        'resolve' => fn () => $session !== '' ? null : 'GW_SHOT_SESSION is not set; skipping the writing panel.',
        'url' => fn () => $url.'/cp/ghostwriter/sessions/'.$session.'/open',
        'wait' => 3,
    ],
    '02-content-plan' => ['url' => fn () => $url.'/cp/ghostwriter/plan', 'wait' => 1.5],
    '03-image-choices' => [
        'resolve' => fn () => $session !== '' ? null : 'GW_SHOT_SESSION is not set; skipping the image choices.',
        'url' => fn () => $url.'/cp/ghostwriter/sessions/'.$session.'/open',
        'after' => "document.querySelector('[data-gw-images]')?.scrollIntoView({block: 'start'})",
        'wait' => 3,
    ],
    '04-voice-guide' => ['url' => fn () => $url.'/cp/ghostwriter/voice', 'wait' => 1.5],
];

$ws = Chrome::launch($chrome);

$ws->send('Page.enable');
$ws->send('Runtime.enable');

// Store images are taken in light mode, whatever the machine prefers.
$ws->send('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-color-scheme', 'value' => 'light']]]);
$ws->send('Emulation.setDeviceMetricsOverride', ['width' => 1600, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);

// Sign in. The form is Vue, so values go in through the native setter and an
// input event, as typing would.
$ws->navigate($url.'/cp/auth/login');
$ws->evaluate(<<<'JS'
    (() => {
        const set = (el, value) => { const s = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set; s.call(el, value); el.dispatchEvent(new Event('input', {bubbles: true})); };
        set(document.querySelector('input[type=email], input[name=email], input[type=text]'), EMAIL);
        set(document.querySelector('input[type=password]'), PASSWORD);
        document.querySelector('button[type=submit]').click();
    })()
JS, ['EMAIL' => $email, 'PASSWORD' => $password]);
$ws->waitFor(fn () => str_contains((string) $ws->evaluate('document.location.pathname'), '/cp') && ! str_contains((string) $ws->evaluate('document.location.pathname'), '/auth/login'), 15);

echo "Signed in.\n";

foreach ($shots as $name => $shot) {
    if (isset($shot['resolve']) && ($skip = $shot['resolve']()) !== null) {
        echo "{$name}: {$skip}\n";

        continue;
    }

    $ws->navigate($shot['url']());
    usleep((int) (($shot['wait'] ?? 1) * 1000000));

    // A trial-mode site greets each fresh browser with a licensing notice.
    $ws->evaluate("[...document.querySelectorAll('button')].find(b => /snooze/i.test(b.textContent))?.click()");
    usleep(400000);

    if (! empty($shot['after'])) {
        $ws->evaluate($shot['after']);
        usleep(600000);
    }

    foreach ([1 => '', 2 => '-2x'] as $scale => $suffix) {
        $ws->send('Emulation.setDeviceMetricsOverride', ['width' => 1600, 'height' => 900, 'deviceScaleFactor' => $scale, 'mobile' => false]);
        usleep(400000);
        $png = base64_decode($ws->send('Page.captureScreenshot', ['format' => 'png', 'captureBeyondViewport' => false])['data']);
        file_put_contents("{$out}/{$name}{$suffix}.png", $png);
    }

    echo "{$name}: saved.\n";
}

echo "Done. Raw shots are in {$out}.\n";
