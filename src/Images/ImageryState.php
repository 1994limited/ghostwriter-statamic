<?php

namespace NineteenNinetyFour\Ghostwriter\Images;

use NineteenNinetyFour\Ghostwriter\Voice\VoiceState;

/**
 * Working state for the image style screen, kept apart from the voice guide's.
 */
class ImageryState extends VoiceState
{
    protected function path(): string
    {
        return dirname((string) config('ghostwriter.sessions_path')).'/imagery.json';
    }
}
