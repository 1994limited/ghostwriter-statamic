<?php

namespace NineteenNinetyFour\Ghostwriter\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Looks over a collection's entries and names the kinds of content in it.
 */
#[MaxTokens(8000)]
class KindScout implements Agent
{
    use Promptable;

    public function __construct(private string $instructions) {}

    public function instructions(): Stringable|string
    {
        return $this->instructions;
    }
}
