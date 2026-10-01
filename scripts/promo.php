#!/usr/bin/env php
<?php

/**
 * The promo video: a 34-second animation of a simplified Control Panel in
 * the brand's style, rendered frame by frame from scripts/promo/promo.html
 * by headless Chrome and encoded with ffmpeg.
 *
 *   php scripts/promo.php [output.mp4]
 *
 * The HTML is one CSS timeline; the script seeks every animation to each
 * frame's time and captures it, so the result is the same on every run.
 * Needs Chrome (GW_SHOT_CHROME to point at it) and ffmpeg on the PATH.
 */

require __DIR__.'/lib/DevToolsSocket.php';
require __DIR__.'/lib/Chrome.php';

$chrome = getenv('GW_SHOT_CHROME') ?: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
$out = $argv[1] ?? __DIR__.'/../docs/promo/ghostwriter-promo.mp4';
$frames = dirname($out).'/frames';
$fps = 30;
$seconds = 34;

if (trim((string) shell_exec('command -v ffmpeg')) === '') {
    fwrite(STDERR, "ffmpeg is needed to encode the video (brew install ffmpeg).\n");
    exit(1);
}

exec('rm -rf '.escapeshellarg($frames));
@mkdir($frames, 0777, true);

$ws = Chrome::launch($chrome, ['--window-size=1920,1080']);
$ws->send('Page.enable');
$ws->send('Runtime.enable');
$ws->send('Emulation.setDeviceMetricsOverride', ['width' => 1920, 'height' => 1080, 'deviceScaleFactor' => 1, 'mobile' => false]);

$ws->navigate('file://'.realpath(__DIR__.'/promo/promo.html'));
$ws->evaluate('document.fonts.ready.then(() => true)');
usleep(800000);

$total = $fps * $seconds;

for ($i = 0; $i < $total; $i++) {
    $ws->evaluate('window.seek('.($i / $fps).')');
    $png = base64_decode($ws->send('Page.captureScreenshot', ['format' => 'jpeg', 'quality' => 92])['data']);
    file_put_contents(sprintf('%s/%05d.jpg', $frames, $i), $png);

    if ($i % $fps === 0) {
        printf("\r%d / %d seconds", $i / $fps, $seconds);
    }
}

echo "\nEncoding…\n";

passthru(sprintf(
    'ffmpeg -y -framerate %d -i %s -c:v libx264 -preset slow -crf 19 -pix_fmt yuv420p -movflags +faststart %s 2>&1 | tail -2',
    $fps,
    escapeshellarg("{$frames}/%05d.jpg"),
    escapeshellarg($out),
));

echo "Done: {$out}\n";
