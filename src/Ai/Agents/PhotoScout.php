<?php

namespace NineteenNinetyFour\Ghostwriter\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Chooses what to search a photo library for, from the words around the
 * place a picture will go.
 */
#[MaxTokens(120)]
class PhotoScout implements Agent
{
    use Promptable;

    public function __construct(private string $instructions) {}

    public function instructions(): Stringable|string
    {
        return $this->instructions;
    }
}
