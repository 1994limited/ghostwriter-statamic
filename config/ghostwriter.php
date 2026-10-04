<?php

use NineteenNinetyFour\Ghostwriter\Drafts\SchemaEntryWriter;

return [

    /*
    |--------------------------------------------------------------------------
    | AI provider and model
    |--------------------------------------------------------------------------
    |
    | "anthropic" (Claude), "openai" (ChatGPT), "gemini" or "openrouter"
    | (Claude, GPT, Gemini and others through one OpenRouter account). Leave
    | the model null to use the provider's default.
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

    // With OpenRouter: the model for each tier, by OpenRouter id, such as
    // "anthropic/claude-opus-5.5". "writing" is the writer, briefs, guides
    // and planning; "quick" is choosing photos and filling a gap. Null uses
    // the settings screen's choice, then OpenRouter's default for the tier.
    'openrouter' => [
        'models' => [
            'writing' => env('GHOSTWRITER_OPENROUTER_WRITING_MODEL'),
            'quick' => env('GHOSTWRITER_OPENROUTER_QUICK_MODEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API keys
    |--------------------------------------------------------------------------
    |
    | Read from .env each time one is needed, and never stored or shown.
    | OpenRouter can also be connected from the settings screen ("Connect with
    | OpenRouter"); that key is kept encrypted, and OPENROUTER_API_KEY wins
    | over it whenever it is set. A key
    | still set in config/ai.php, from when Ghostwriter used the Laravel AI
    | SDK, is used when the one here is empty.
    |
    */

    'keys' => [
        'anthropic' => env('ANTHROPIC_API_KEY'),
        'openai' => env('OPENAI_API_KEY'),
        'gemini' => env('GEMINI_API_KEY'),
        'openrouter' => env('OPENROUTER_API_KEY'),
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
        'openrouter' => env('GHOSTWRITER_OPENROUTER_BASE_URL'),
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
    | Images need a provider that makes them: "openai", "gemini" or
    | "openrouter". Claude does not. Leave the provider null to use whichever
    | of those has a key, in that order (OPENAI_API_KEY / GEMINI_API_KEY in
    | .env, or OpenRouter). With OpenRouter, an image model uses its spelling,
    | such as "openai/gpt-image-2.5-sunburst".
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
    | Shutterstock licenses for your connected account: set its keys, then
    | Connect account on the settings screen. Getty Images and iStock are
    | coming: their keys can be set now, and they switch on once
    | Ghostwriter ships their adapter.
    |
    */

    'stock' => [
        'keys' => [
            'getty' => env('GETTY_API_KEY'),
            'getty_secret' => env('GETTY_API_SECRET'),
            'shutterstock' => env('SHUTTERSTOCK_API_KEY'),
            'shutterstock_secret' => env('SHUTTERSTOCK_API_SECRET'),
        ],

        // Shutterstock's sandbox: searching works as normal, and licensing
        // charges nothing and returns a watermarked file. Null uses the
        // sandbox only when APP_ENV is local.
        'shutterstock_sandbox' => env('GHOSTWRITER_SHUTTERSTOCK_SANDBOX'),

        // "Demo stock (no charge)": a pretend paid library that charges
        // nothing and calls nobody, to try the whole preview and licence
        // flow. Null turns it on only when APP_ENV is local; true turns it
        // on elsewhere too. It never runs in production.
        'demo' => env('GHOSTWRITER_STOCK_DEMO'),

        // Replaced by publish.on_unfinished below, which covers unlicensed
        // previews too; still read when that isn't set.
        'on_publish' => env('GHOSTWRITER_STOCK_ON_PUBLISH'),
        // Where "Search in" starts: "free", "everything" or a library's ID.
        'default_source' => env('GHOSTWRITER_STOCK_DEFAULT_SOURCE'),
        'include_editorial' => env('GHOSTWRITER_STOCK_INCLUDE_EDITORIAL'),

        // In Live Preview, show signed-in editors the comp in place of the
        // stand-in (any src or srcset ending in the stand-in's file name).
        // Shared preview links opened while signed out always get the
        // stand-in.
        'live_preview' => (bool) env('GHOSTWRITER_STOCK_LIVE_PREVIEW', true),

        // Previews no page uses any more are removed after this many days.
        'unused_preview_days' => (int) env('GHOSTWRITER_STOCK_UNUSED_PREVIEW_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Finish this page
    |--------------------------------------------------------------------------
    |
    | Ghostwriter marks what only a person can finish: a fact to add
    | ([[ask: adult ticket price]]), a link to choose (#gw-link:contact-page),
    | an image placeholder, a stock preview not licensed yet. The entry's
    | form counts them by Save and a guide walks through them.
    |
    | publish.on_unfinished (a settings-screen field too: a value here wins):
    | when a page with any of these is published, "block" (the default)
    | refuses it with a message on each field; "warn" lets it through with
    | a warning. Saving unpublished always works.
    |
    | finish.open_after_draft: the guide opens by itself when a draft is put
    | into the form. Otherwise it stays as each person last left it.
    |
    */

    'publish' => [
        'on_unfinished' => env('GHOSTWRITER_ON_UNFINISHED_PUBLISH'),
    ],

    'finish' => [
        'open_after_draft' => (bool) env('GHOSTWRITER_FINISH_OPEN_AFTER_DRAFT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Suggest edits and Content to revisit
    |--------------------------------------------------------------------------
    |
    | Suggest edits reads a page against the voice guide when someone asks.
    | Content to revisit ranks published pages by free checks (no model):
    | dates, links, alt text, empty fields and age. The settings screen sets
    | the three switches; anything set here wins and locks it there.
    |
    | - claims: flag counts and claims as facts to check (on).
    | - external_links: check links to other sites once a week (off).
    | - age_in_full: dated collections where age counts in full.
    | - language: the language the phrase checks read ("New for 2024",
    |   "applications close"): en, de, fr, nl or es. Null uses each
    |   site's own.
    |
    | Reviews are kept in edit_reviews_path, the list in revisit_path (both
    | beside the sessions when null).
    |
    */

    'suggest' => [
        'claims' => env('GHOSTWRITER_SUGGEST_CLAIMS'),
        'language' => env('GHOSTWRITER_CONTENT_LANGUAGE'),
    ],

    'revisit' => [
        'external_links' => env('GHOSTWRITER_REVISIT_EXTERNAL_LINKS'),
        'age_in_full' => null,
    ],

    'edit_reviews_path' => null,

    'revisit_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Page preview
    |--------------------------------------------------------------------------
    |
    | The Preview tab renders the unsaved draft through the site's own
    | templates (Statamic's Live Preview), in a frame beside the
    | conversation. Nothing is saved. Templates can tell a Ghostwriter
    | render by {{ live_preview:ghostwriter }}.
    |
    | The frame's pages get a Content-Security-Policy that blocks
    | third-party scripts (tag managers, analytics, chat widgets) and their
    | beacons. script_hosts allows hosts the site's own scripts come from,
    | such as a CDN: a list, or a comma-separated string in .env.
    |
    | timeout: seconds the panel waits for a render before it gives up.
    |
    */

    'preview' => [
        'enabled' => (bool) env('GHOSTWRITER_PREVIEW', true),
        'script_hosts' => env('GHOSTWRITER_PREVIEW_SCRIPT_HOSTS', []),
        'timeout' => (int) env('GHOSTWRITER_PREVIEW_TIMEOUT', 8),
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

    /*
    |--------------------------------------------------------------------------
    | Scripted replies for end-to-end tests
    |--------------------------------------------------------------------------
    |
    | The folder of scenario files the end-to-end tests play instead of
    | calling a model. Only on a local or testing environment, never in
    | production, and only for a request that names a scenario in the
    | X-Ghostwriter-Fake header (or the ghostwriter_fake cookie), or a job
    | queued by one. Leave it null (the default) everywhere else. See
    | docs/testing.md.
    |
    */

    'testing' => [
        'fake_scenarios' => env('GHOSTWRITER_FAKE_SCENARIOS'),
    ],

];
