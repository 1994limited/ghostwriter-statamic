<?php

use NineteenNinetyFour\Ghostwriter\Drafts\SchemaEntryWriter;

return [

    /*
    |--------------------------------------------------------------------------
    | AI provider and model
    |--------------------------------------------------------------------------
    |
    | "anthropic" (Claude), "openai" (ChatGPT) or "gemini". Leave the model
    | null to use the provider's default.
    |
    | These, and the other settings marked below, can also be chosen on the
    | addon's settings screen in the Control Panel. A value set here wins:
    | the screen shows it locked. Leave one null (or an empty list) to let
    | the settings screen decide; with neither, Claude is used.
    |
    */

    'provider' => env('GHOSTWRITER_PROVIDER'),

    'model' => env('GHOSTWRITER_MODEL'),

    // Seconds to wait for one response. Long drafts take a while. A call
    // that finds the provider busy is tried up to three times, so queued
    // jobs are given three times this, plus a minute. Set here only.
    'timeout' => (int) env('GHOSTWRITER_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | API keys
    |--------------------------------------------------------------------------
    |
    | Read from .env each time one is needed, and never stored or shown. A key
    | still set in config/ai.php, from when Ghostwriter used the Laravel AI
    | SDK, is used when the one here is empty.
    |
    */

    'keys' => [
        'anthropic' => env('ANTHROPIC_API_KEY'),
        'openai' => env('OPENAI_API_KEY'),
        'gemini' => env('GEMINI_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | To send a provider's requests through a gateway that speaks the same
    | API, set its address here, such as "https://gateway.example.com/v1" for
    | OpenAI. It must be https://, except on localhost. Null uses the
    | provider's own.
    |
    */

    'base_urls' => [
        'anthropic' => env('GHOSTWRITER_ANTHROPIC_BASE_URL'),
        'openai' => env('GHOSTWRITER_OPENAI_BASE_URL'),
        'gemini' => env('GHOSTWRITER_GEMINI_BASE_URL'),
    ],

    // Whether a request Claude declines is passed to the model Anthropic
    // recommends instead, on the models that support it.
    'anthropic_fallbacks' => (bool) env('GHOSTWRITER_ANTHROPIC_FALLBACKS', true),

    // The log channel calls are recorded on: provider, model, tokens and
    // time, never the words sent or received. Replies that were cut off or
    // could not be read are noted here too. Null uses the default.
    'log_channel' => env('GHOSTWRITER_LOG_CHANNEL'),

    // For tracking down a problem: when a reply can't be read, put the
    // model's whole reply in the log entry (under "reply"), not just what was
    // wrong with it. Replies can hold what your site has published and
    // what was written in a session, so leave it off otherwise. Prompts and
    // keys are never logged. Set here only.
    'debug' => [
        'log_replies' => (bool) env('GHOSTWRITER_LOG_REPLIES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Images
    |--------------------------------------------------------------------------
    |
    | Images need a provider that makes them: "openai" or "gemini". Claude
    | does not. Leave the provider null to use whichever of those has an API
    | key (OPENAI_API_KEY / GEMINI_API_KEY in .env).
    | With no such key, images cannot be made, but photographs can still be
    | found: on Unsplash, Pexels and Pixabay with a free API key for each, and on
    | Openverse (public-domain and CC0 work only) with no key at all.
    |
    */

    'images' => [
        // Settings-screen fields too: a value here wins.
        'provider' => env('GHOSTWRITER_IMAGE_PROVIDER'),
        'model' => env('GHOSTWRITER_IMAGE_MODEL'),
        'unsplash_key' => env('UNSPLASH_ACCESS_KEY'),
        'pexels_key' => env('PEXELS_API_KEY'),
        'pixabay_key' => env('PIXABAY_API_KEY'),
        // Settings-screen field too ("Search Openverse"): a value here wins.
        'openverse' => env('GHOSTWRITER_OPENVERSE'),

        // The image style guide: what the site's pictures look like, in words.
        'guide_path' => resource_path('ghostwriter/imagery.md'),
        'guide_samples' => 10,

        // Mark each image field a new entry should have but the draft left
        // empty with a striped placeholder, so the layout shows as it will
        // be. On unless turned off here or on the settings screen; a value
        // here wins.
        'placeholders' => env('GHOSTWRITER_PLACEHOLDER_IMAGES'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tone of voice guide
    |--------------------------------------------------------------------------
    |
    | The guide is a markdown file in your project, so it is versioned with
    | the rest of the site. "collections" limits which collections are read
    | when it is generated; leave it empty to let the settings screen decide,
    | and so read every collection unless some are chosen there.
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
    | The collections Ghostwriter offers to write for. Leave this empty to let
    | the settings screen decide, and so offer every collection unless some
    | are chosen there. A list here wins over the screen.
    |
    */

    'collections' => [],

    'types_path' => resource_path('ghostwriter/types'),

    // Whether Get started looks over each collection for kinds of content
    // worth teaching by itself, the first time it is seen and again as
    // entries are published. Elsewhere kinds are only suggested on a click.
    // On unless turned off here or on the settings screen; a value here wins.
    'suggest_kinds' => env('GHOSTWRITER_SUGGEST_KINDS'),

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
    | Stock image ledger
    |--------------------------------------------------------------------------
    |
    | A record of every stock photo Ghostwriter put into the site, free or
    | paid: where it is used, its licence, who licensed it and when, and its
    | credit line. One YAML file per record, in content/ so it is committed
    | with the site and travels between environments like entries do.
    | Records are never deleted.
    |
    */

    'stock_path' => base_path('content/ghostwriter/stock'),

    /*
    |--------------------------------------------------------------------------
    | Stock photos from paid libraries
    |--------------------------------------------------------------------------
    |
    | A paid photo goes into a page as a preview: a labelled stand-in image
    | in the field, and the library's watermarked comp kept privately for
    | signed-in editors. A manager then licenses it from your own account
    | with the library, and the stand-in's file is swapped for the licensed
    | one. Keys and secrets live only in .env, are read each time and are
    | never stored or shown. A library is offered only once its keys are
    | set (and Ghostwriter has its adapter); each can be switched off on
    | the settings screen.
    |
    | Getty Images, iStock and Shutterstock are coming: their keys can be
    | set now, and they switch on once Ghostwriter ships their adapters.
    |
    */

    'stock' => [
        'keys' => [
            'getty' => env('GETTY_API_KEY'),
            'getty_secret' => env('GETTY_API_SECRET'),
            'shutterstock' => env('SHUTTERSTOCK_API_KEY'),
            'shutterstock_secret' => env('SHUTTERSTOCK_API_SECRET'),
        ],

        // "Demo stock (no charge)": a pretend paid library that charges
        // nothing and calls nobody, to try the whole preview and licence
        // flow. Null turns it on only when APP_ENV is local; true turns it
        // on elsewhere too. It never runs in production.
        'demo' => env('GHOSTWRITER_STOCK_DEMO'),

        // Settings-screen fields too: a value here wins. When a page holding
        // an unlicensed preview is published: "block" (the default) refuses
        // it with a message on the image field; "warn" lets it through with
        // a warning.
        'on_publish' => env('GHOSTWRITER_STOCK_ON_PUBLISH'),
        // Where "Search in" starts: "free", "everything" or a library's ID.
        'default_source' => env('GHOSTWRITER_STOCK_DEFAULT_SOURCE'),
        'include_editorial' => env('GHOSTWRITER_STOCK_INCLUDE_EDITORIAL'),

        // Previews no page uses any more are removed after this many days.
        'unused_preview_days' => (int) env('GHOSTWRITER_STOCK_UNUSED_PREVIEW_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared conversations
    |--------------------------------------------------------------------------
    |
    | On, everyone with the Ghostwriter permission sees and can carry on with
    | every piece: from the Overview, the content plan and the entry. Each
    | message shows who sent it, and Ghostwriter answers one at a time. Off,
    | each conversation is its starter's alone (super users aside).
    |
    */

    'shared_conversations' => (bool) env('GHOSTWRITER_SHARED_CONVERSATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Drafts start unpublished
    |--------------------------------------------------------------------------
    |
    | When a draft is put into the form of a new entry, or of one that isn't
    | published, the form's Published toggle is switched off, so the entry
    | can be saved straight away and goes live only when someone switches it
    | on. An entry that is already published is left as it is. False leaves
    | the toggle alone everywhere.
    |
    */

    'drafts_unpublished' => (bool) env('GHOSTWRITER_DRAFTS_UNPUBLISHED', true),

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
