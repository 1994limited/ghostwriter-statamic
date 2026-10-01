<?php

namespace NineteenNinetyFour\Ghostwriter\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Turns a draft into the few words worth typing into a photo library.
 */
#[MaxTokens(60)]
class PhotoResearcher implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You choose search terms for stock photo libraries. Given a web page\'s title and summary, reply with two to four plain words naming a concrete, photographable subject that would suit the page: objects, places or scenes, never brand names, abstract ideas or the word "website". Reply with the words only, lower case, no punctuation.';
    }
}
