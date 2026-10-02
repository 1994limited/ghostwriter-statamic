<?php

namespace NineteenNinetyFour\Ghostwriter\Ai;

/**
 * Whether a model name looks like it belongs to another provider: "gpt-…"
 * typed while Claude is chosen, say. It only warns: a name it does not
 * recognise is let through, so new models need no update here.
 */
class ModelCheck
{
    /** How each provider's model names start. */
    private const FAMILIES = [
        'anthropic' => ['claude'],
        'openai' => ['gpt', 'chatgpt', 'o1', 'o3', 'o4', 'dall-e'],
        'gemini' => ['gemini', 'imagen', 'gemma'],
    ];

    private const NAMES = [
        'anthropic' => 'Claude (Anthropic)',
        'openai' => 'ChatGPT (OpenAI)',
        'gemini' => 'Gemini (Google)',
    ];

    /**
     * What to say when the model is another provider's, or null when it
     * fits or cannot be told.
     */
    public function mismatch(?string $provider, ?string $model, string $label = 'Model'): ?string
    {
        $model = strtolower(trim((string) $model));

        if ($model === '' || ! isset(self::FAMILIES[$provider])) {
            return null;
        }

        $owner = $this->owner($model);

        if ($owner === null || $owner === $provider) {
            return null;
        }

        return "{$label} \"{$model}\" looks like a ".self::NAMES[$owner].' model, but the provider is '.self::NAMES[$provider].'. Check both, or the calls will fail.';
    }

    private function owner(string $model): ?string
    {
        // A gateway's "vendor/model" names the model after the slash.
        $model = str_contains($model, '/') ? substr($model, strrpos($model, '/') + 1) : $model;

        foreach (self::FAMILIES as $provider => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($model === $prefix || str_starts_with($model, $prefix.'-') || str_starts_with($model, $prefix.'.') || preg_match('/^'.preg_quote($prefix, '/').'\d/', $model)) {
                    return $provider;
                }
            }
        }

        return null;
    }
}
