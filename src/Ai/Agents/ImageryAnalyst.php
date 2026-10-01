<?php

namespace NineteenNinetyFour\Ghostwriter\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Looks at the images one section of a site uses and describes their style
 * in words, for whoever chooses the next one.
 */
#[MaxTokens(1500)]
class ImageryAnalyst implements Agent
{
    use Promptable;

    public function __construct(private string $instructions) {}

    public function instructions(): Stringable|string
    {
        return $this->instructions;
    }
}
