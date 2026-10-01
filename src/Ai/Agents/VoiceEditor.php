<?php

namespace NineteenNinetyFour\Ghostwriter\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Applies one requested change to an existing tone of voice guide.
 *
 * The instructions are built by Studio, so the same class serves every site,
 * content type and voice guide; keeping it a named class lets tests fake it.
 */
#[MaxTokens(8000)]
class VoiceEditor implements Agent
{
    use Promptable;

    public function __construct(private string $instructions) {}

    public function instructions(): Stringable|string
    {
        return $this->instructions;
    }
}
