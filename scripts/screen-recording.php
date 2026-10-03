#!/usr/bin/env php
<?php

/**
 * A screen recording of the real product: headless Chrome records the
 * Control Panel as it is driven through the main flows, with brand title
 * cards between scenes. The styled promo is scripts/promo.php. Frames go to docs/promo/frames/ with a concat list;
 * ffmpeg turns them into an MP4 (scripts/promo.php encodes it when ffmpeg
 * is on the PATH, or run the printed command yourself).
 *
 * Usage:
 *   GW_SHOT_URL=https://1994.test GW_SHOT_EMAIL=… GW_SHOT_PASSWORD=… \
 *   GW_SHOT_SESSION=<a session id with a draft and image options> \
 *   GW_SHOT_COLLECTION=articles php scripts/screen-recording.php
 */

require __DIR__.'/lib/DevToolsSocket.php';
require __DIR__.'/lib/Chrome.php';
require __DIR__.'/lib/Cp.php';

$url = rtrim((string) getenv('GW_SHOT_URL'), '/');
$email = (string) getenv('GW_SHOT_EMAIL');
$password = (string) getenv('GW_SHOT_PASSWORD');
$session = (string) getenv('GW_SHOT_SESSION');
$collection = getenv('GW_SHOT_COLLECTION') ?: 'articles';
$chrome = getenv('GW_SHOT_CHROME') ?: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
$out = $argv[1] ?? __DIR__.'/../docs/promo/recording';
$frames = "{$out}/frames";

if ($url === '' || $email === '' || $password === '' || $session === '') {
    fwrite(STDERR, "Set GW_SHOT_URL, GW_SHOT_EMAIL, GW_SHOT_PASSWORD and GW_SHOT_SESSION.\n");
    exit(1);
}

exec('rm -rf '.escapeshellarg($frames));
@mkdir($frames, 0777, true);

$ws = Chrome::launch($chrome);
$cp = new Cp($ws, $url);

$ws->send('Page.enable');
$ws->send('Runtime.enable');
$ws->send('Emulation.setDeviceMetricsOverride', ['width' => 1600, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
$ws->send('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-color-scheme', 'value' => 'light']]]);

/**
 * Every frame the screencast sends is kept with the moment it arrived; the
 * list of frames and how long each is shown is what ffmpeg assembles.
 */
$list = [];
$n = 0;
$last = null;

$ws->onEvent(function (string $method, array $params) use (&$list, &$n, &$last, $frames, $ws): void {
    if ($method !== 'Page.screencastFrame') {
        return;
    }

    $ws->send('Page.screencastFrameAck', ['sessionId' => $params['sessionId']]);

    $file = sprintf('%s/%05d.jpg', $frames, ++$n);
    file_put_contents($file, base64_decode($params['data']));

    $now = microtime(true);

    if ($last !== null) {
        $list[count($list) - 1]['duration'] = max(0.02, $now - $last);
    }

    $list[] = ['file' => $file, 'duration' => 0.1];
    $last = $now;
});

$record = function (float $seconds) use ($ws): void {
    $ws->pump($seconds);
};

$startRecording = function () use ($ws, &$last): void {
    $last = null;
    $ws->send('Page.startScreencast', ['format' => 'jpeg', 'quality' => 85, 'maxWidth' => 1600, 'maxHeight' => 900, 'everyNthFrame' => 1]);
};

$stopRecording = function () use ($ws, &$list, &$last): void {
    $ws->send('Page.stopScreencast');

    if ($list && $last !== null) {
        $list[count($list) - 1]['duration'] = max($list[count($list) - 1]['duration'], 0.6);
    }

    $last = null;
};

/**
 * A brand title card: one still frame, held.
 */
$card = function (string $heading, string $line = '', bool $end = false, float $hold = 2.5) use ($ws, &$list, &$n, $frames): void {
    $logo = file_get_contents(__DIR__.'/../resources/brand/horizontal-colour.svg');
    $mark = file_get_contents(__DIR__.'/../resources/brand/mark-colour.svg');
    $html = '<!doctype html><html><head><meta charset="utf-8"><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,500;1,6..72,500&family=Public+Sans:wght@400;500&display=swap" rel="stylesheet">'
        .'<style>html,body{margin:0;height:100%;background:#F3EEE4;color:#1C1E24;font-family:"Public Sans",system-ui,sans-serif}'
        .'.card{height:100%;display:flex;flex-direction:column;justify-content:center;padding:0 140px;box-sizing:border-box}'
        .'.logo{width:360px;margin-bottom:56px}.logo svg{width:100%;height:auto}'
        .'h1{font-family:Newsreader,Georgia,serif;font-weight:500;font-size:84px;line-height:1.08;margin:0 0 22px;letter-spacing:-0.01em;max-width:1100px}'
        .'p{font-size:34px;line-height:1.4;margin:0;color:#5E5A52;max-width:1000px}'
        .'.end{align-items:center;text-align:center}.end .mark{width:140px;margin-bottom:36px}.end h1{font-size:72px}.end code{display:inline-block;margin-top:40px;padding:18px 30px;border:1.5px solid #CFC5B2;border-radius:12px;font-size:28px;color:#2B3A64;background:#fff}'
        .'.rule{position:absolute;left:140px;right:140px;bottom:90px;height:1.5px;background:#E0D8C8}.by{position:absolute;right:140px;bottom:40px;font-size:22px;color:#5E5A52}'
        .'</style></head><body>'
        .($end
            ? '<div class="card end"><div class="mark">'.$mark.'</div><h1>'.htmlspecialchars($heading).'</h1><p>'.htmlspecialchars($line).'</p><code>composer require 1994/ghostwriter-statamic</code></div>'
            : '<div class="card"><div class="logo">'.$logo.'</div><h1>'.htmlspecialchars($heading).'</h1>'.($line !== '' ? '<p>'.htmlspecialchars($line).'</p>' : '').'</div><div class="rule"></div><div class="by">For Statamic 6 · by 1994</div>')
        .'</body></html>';

    $ws->navigate('data:text/html;base64,'.base64_encode($html));
    $ws->evaluate('document.fonts.ready.then(() => true)');
    usleep(400000);

    $file = sprintf('%s/%05d.jpg', $frames, ++$n);
    file_put_contents($file, base64_decode($ws->send('Page.captureScreenshot', ['format' => 'jpeg', 'quality' => 92])['data']));
    $list[] = ['file' => $file, 'duration' => $hold];
};

$scroll = function (int $by, int $steps, float $pause) use ($ws, $record): void {
    for ($i = 0; $i < $steps; $i++) {
        $ws->evaluate("(() => { const el = document.querySelector('[data-ui-stack-content] .overflow-y-auto, [data-ui-stack-content]') || document.scrollingElement; (el.querySelector && el.querySelector('.overflow-y-auto') || el).scrollBy({top: {$by}, behavior: 'smooth'}); })()");
        $record($pause);
    }
};

echo "Signing in…\n";
$cp->signIn($email, $password);

// 1. Title.
$card('Writes in your voice, inside Statamic.', 'Ghostwriter learns how your site writes, then drafts new entries beside the form.', hold: 3.5);

// 2. Write with Ghostwriter: the panel opens on the create screen.
$card('Start from the entry you’re making.', 'A button beside Save & Publish opens Ghostwriter over the form.', hold: 2.2);
$ws->navigate($url."/cp/collections/{$collection}/entries/create/default");
$record(0.5);
$cp->snooze();
$startRecording();
$record(1.2);
$cp->click('^Write with Ghostwriter');
$record(3.0);
$stopRecording();

// 3. A draft, talked through.
$card('Answer a short brief. Talk the draft through.', 'Ask for changes in plain words, or click any line to edit it.', hold: 2.4);
$ws->navigate($url."/cp/ghostwriter/sessions/{$session}/open");
$record(0.5);
$cp->snooze();
$startRecording();
$record(2.0);
$scroll(120, 8, 0.35);
$cp->click('^Text$');
$record(1.6);
$cp->click('^Blocks$');
$record(1.2);
$stopRecording();

// 4. The image button on a field.
$card('Images that match the ones you already use.', 'Find a photo or make one, right on the image field.', hold: 2.4);
$ws->navigate($url."/cp/collections/{$collection}/entries/create/default");
$record(0.5);
$cp->snooze();
$startRecording();
$record(1.0);
$ws->evaluate("(() => { const b = [...document.querySelectorAll('button')].find(b => (b.getAttribute('aria-label') || b.title || '') === 'Ghostwriter' && b.closest('[data-ui-field-header], .form-group, [class*=field]')); b?.click(); return !!b; })()");
$record(2.6);
$stopRecording();

// 5. The content plan.
$card('Plan what the site is missing.', 'Ghostwriter reads everything you have and suggests what to write next.', hold: 2.2);
$ws->navigate($url.'/cp/ghostwriter/plan');
$record(0.5);
$cp->snooze();
$startRecording();
$record(1.2);
$scroll(140, 6, 0.35);
$stopRecording();

// 6. The voice guide.
$card('It learns how you write first.', 'A tone of voice guide from your own entries, edited like any other.', hold: 2.2);
$ws->navigate($url.'/cp/ghostwriter/voice');
$record(0.5);
$cp->snooze();
$startRecording();
$record(1.2);
$scroll(120, 6, 0.35);
$stopRecording();

// 7. End.
$card('Ghostwriter', 'For Statamic 6. By 1994.', end: true, hold: 4.0);

// The list ffmpeg's concat demuxer reads: each frame and how long it shows.
$concat = "ffconcat version 1.0\n";

foreach ($list as $frame) {
    $concat .= "file '".basename($frame['file'])."'\nduration ".number_format($frame['duration'], 3, '.', '')."\n";
}

$concat .= "file '".basename(end($list)['file'])."'\n";
file_put_contents("{$frames}/list.txt", $concat);

$total = array_sum(array_column($list, 'duration'));
printf("%d frames, %.1f seconds. Frames and list in %s.\n", count($list), $total, $frames);

$command = sprintf(
    'ffmpeg -y -f concat -safe 0 -i %s -vf "scale=1600:900:force_original_aspect_ratio=decrease,pad=1600:900:(ow-iw)/2:(oh-ih)/2:color=#F3EEE4,fps=30,format=yuv420p" -c:v libx264 -preset slow -crf 20 -movflags +faststart %s',
    escapeshellarg("{$frames}/list.txt"),
    escapeshellarg("{$out}/ghostwriter-recording.mp4"),
);

if (trim((string) shell_exec('command -v ffmpeg')) !== '') {
    echo "Encoding…\n";
    passthru($command.' 2>&1 | tail -3');
    echo "Done: {$out}/ghostwriter-recording.mp4\n";
} else {
    echo "ffmpeg is not installed. Encode with:\n\n{$command}\n";
}
