<?php

namespace NineteenNinetyFour\Ghostwriter\Planning;

use NineteenNinetyFour\Ghostwriter\Voice\VoiceState;

/**
 * Working state for the content plan screen: whether ideas are being looked for.
 */
class PlanState extends VoiceState
{
    protected function path(): string
    {
        return dirname((string) config('ghostwriter.sessions_path')).'/plan.json';
    }
}
