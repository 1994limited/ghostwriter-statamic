<?php

use NineteenNinetyFour\Ghostwriter\Drafts\SchemaEntryWriter;

return [

    /*
    |--------------------------------------------------------------------------
    | AI provider and model
    |--------------------------------------------------------------------------
    |
    | Any provider configured in config/ai.php: "anthropic", "openai", and so
    | on. The API key lives there (ANTHROPIC_API_KEY / OPENAI_API_KEY in .env),
    | never here. Leave the model null to use the provider's default.
    |
    | These, and the collection lists below, are defaults. Whatever is chosen
    | on the addon's settings screen in the Control Panel takes precedence.
    |
    */

    'provider' => env('GHOSTWRITER_PROVIDER', 'anthropic'),

    'model' => env('GHOSTWRITER_MODEL'),

    // Seconds to wait for one response. Long drafts take a while.
    'timeout' => (int) env('GHOSTWRITER_TIMEOUT', 180),

    /*
    |--------------------------------------------------------------------------
    | Images
    |--------------------------------------------------------------------------
    |
    | Images need a provider that makes them: "openai", "gemini" or "xai".
    | Claude does not. Leave the provider null to use whichever of those has
    | an API key in config/ai.php (OPENAI_API_KEY / GEMINI_API_KEY in .env).
    | With no such key, images cannot be made, but photographs can still be
    | found: on Unsplash, Pexels and Pixabay with a free API key for each, and on
    | Openverse (public-domain and CC0 work only) with no key at all.
    |
    */

    'images' => [
        'provider' => env('GHOSTWRITER_IMAGE_PROVIDER'),
        'model' => env('GHOSTWRITER_IMAGE_MODEL'),
        'unsplash_key' => env('UNSPLASH_ACCESS_KEY'),
        'pexels_key' => env('PEXELS_API_KEY'),
        'pixabay_key' => env('PIXABAY_API_KEY'),
        'openverse' => (bool) env('GHOSTWRITER_OPENVERSE', true),

        // The image style guide: what the site's pictures look like, in words.
        'guide_path' => resource_path('ghostwriter/imagery.md'),
        'guide_samples' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tone of voice guide
    |--------------------------------------------------------------------------
    |
    | The guide is a markdown file in your project, so it is versioned with
    | the rest of the site. "collections" limits which collections are read
    | when it is generated; leave it empty to read every collection.
    |
    */

    'voice' => [
        'path' => resource_path('ghostwriter/voice.md'),
        'collections' => [],
        'max_entries' => 24,
        'max_chars_per_entry' => 6000,
        'max_chars' => 90000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | The collections Ghostwriter offers to write for. Leave this empty to
    | offer every collection. Each one is "learned" once: Ghostwriter reads its
    | blueprint and existing entries and saves a content type, which you can
    | then edit, in the types directory below.
    |
    */

    'collections' => [],

    'types_path' => resource_path('ghostwriter/types'),

    /*
    |--------------------------------------------------------------------------
    | Content plan
    |--------------------------------------------------------------------------
    |
    | Ideas for entries the site does not have yet, suggested by Ghostwriter
    | or added by hand. One YAML file, versioned with the project.
    |
    */

    'plan' => [
        'path' => resource_path('ghostwriter/ideas.yaml'),
        'suggestions' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sessions
    |--------------------------------------------------------------------------
    |
    | Questionnaire answers, the conversation and the working draft are kept
    | as JSON files here. They are working state, not content.
    |
    */

    'sessions_path' => storage_path('ghostwriter/sessions'),

    /*
    |--------------------------------------------------------------------------
    | Entry writer
    |--------------------------------------------------------------------------
    |
    | Used when a draft is saved straight to an entry rather than applied to
    | an open publish form. The default reads the collection's blueprint and
    | fills whatever fields it finds.
    |
    */

    'writer' => SchemaEntryWriter::class,

];
