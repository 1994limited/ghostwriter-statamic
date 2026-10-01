<?php

namespace NineteenNinetyFour\Ghostwriter\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Studies a collection's fields and existing entries and writes the brief
 * questions and guidance for writing a new entry there.
 */
#[MaxTokens(16000)]
class TypeAnalyst implements Agent
{
    use Promptable;

    public function __construct(private string $instructions) {}

    public function instructions(): Stringable|string
    {
        return $this->instructions;
    }
}
