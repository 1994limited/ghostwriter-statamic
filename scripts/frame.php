#!/usr/bin/env php
<?php

/**
 * Set a raw screenshot in a brand frame.
 *
 *   php scripts/frame.php frame.svg raw.png out.png [x y width]
 *
 * The frame is an SVG at 1600×900 with a panel the screenshot drops into
 * (x, y, width in frame pixels; the shot runs off the bottom edge). Output
 * is written at 1600×900 and, as out-2x.png, at 3200×1800 from the 2× raw
 * shot beside the raw one (raw-2x.png) when it exists. Needs Imagick.
 */
[$frame, $raw, $out] = [$argv[1] ?? null, $argv[2] ?? null, $argv[3] ?? null];
$x = (int) ($argv[4] ?? 120);
$y = (int) ($argv[5] ?? 320);
$width = (int) ($argv[6] ?? 1360);

if (! $frame || ! $raw || ! $out || ! is_file($frame) || ! is_file($raw)) {
    fwrite(STDERR, "Usage: php scripts/frame.php frame.svg raw.png out.png [x y width]\n");
    exit(1);
}

foreach ([1 => [$raw, $out], 2 => [preg_replace('/\.png$/', '-2x.png', $raw), preg_replace('/\.png$/', '-2x.png', $out)]] as $scale => [$source, $target]) {
    if (! is_file($source)) {
        continue;
    }

    $canvas = new Imagick;
    $canvas->setBackgroundColor(new ImagickPixel('transparent'));
    $canvas->setResolution(96 * $scale, 96 * $scale);
    $canvas->readImageBlob((string) preg_replace('/<metadata>.*?<\/metadata>/s', '', (string) file_get_contents($frame)));
    $canvas->setImageFormat('png');

    $shot = new Imagick($source);
    $shot->resizeImage($width * $scale, 0, Imagick::FILTER_LANCZOS, 1);

    // Rounded corners on the shot, as a screen in a frame has.
    $mask = new Imagick;
    $mask->newImage($shot->getImageWidth(), $shot->getImageHeight(), new ImagickPixel('transparent'));
    $draw = new ImagickDraw;
    $draw->setFillColor('white');
    $draw->roundRectangle(0, 0, $shot->getImageWidth() - 1, $shot->getImageHeight() - 1, 12 * $scale, 12 * $scale);
    $mask->drawImage($draw);
    $shot->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);

    $canvas->compositeImage($shot, Imagick::COMPOSITE_OVER, $x * $scale, $y * $scale);
    $canvas->cropImage(1600 * $scale, 900 * $scale, 0, 0);
    $canvas->writeImage($target);

    echo "{$target}\n";
}
