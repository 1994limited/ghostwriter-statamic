<?php

/*
 * Writes lang/{de,fr,nl,es}.json from ghostwriter-core's translations of
 * its SEO strings (resources/lang/{de,fr,nl,es}/{gaps,suggest,revisit,seo}.php):
 * the addon translates core's messages with `__()`, keyed by core's English
 * source string, so each file maps that English to the translation.
 * Laravel's `:name` parameters are kept as they are. Run it after updating
 * core; a test fails while the files and core differ.
 *
 *     php scripts/sync-core-translations.php
 */

require dirname(__DIR__).'/vendor/autoload.php';

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;

/**
 * @return array<string, string>
 */
function ghostwriter_core_translations(string $language): array
{
    $out = [];

    foreach (['gaps', 'suggest', 'revisit', 'seo'] as $namespace) {
        $english = Message::strings($namespace);

        foreach (Message::translations($namespace, $language) as $key => $text) {
            if (! isset($english[$key])) {
                continue;
            }

            if (isset($out[$english[$key]]) && $out[$english[$key]] !== $text) {
                throw new RuntimeException("Two translations of “{$english[$key]}” in {$language}: “{$out[$english[$key]]}” and “{$text}”.");
            }

            $out[$english[$key]] = $text;
        }
    }

    ksort($out);

    return $out;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    foreach (Message::TRANSLATED as $language) {
        $strings = ghostwriter_core_translations($language);
        file_put_contents(dirname(__DIR__)."/lang/{$language}.json", json_encode($strings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        echo count($strings)." strings written to lang/{$language}.json\n";
    }
}
