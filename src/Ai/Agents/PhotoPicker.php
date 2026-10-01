<?php

namespace NineteenNinetyFour\Ghostwriter\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Looks at the images a site already uses and at a set of candidate
 * photographs, and says which candidates would sit best beside them.
 */
#[MaxTokens(120)]
class PhotoPicker implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You choose photographs for a website. You are shown reference images the site already uses in one place, then numbered candidate photographs. Choose the candidates that would sit best beside the references: the same kind of image, composition, palette, lighting and mood, and a subject that suits the page described. Prefer candidates from different searches so the choices differ from one another. Avoid anything with visible brand names, watermarks or text. Reply with the chosen numbers only, best first, separated by commas.';
    }
}
