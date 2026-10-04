<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Concerns;

use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;

/**
 * Two sites (English and Welsh) and two collections, Pages and a dated
 * Journal (handles of their own, so their blueprints, which Statamic keeps
 * on disk, never meet another test's), for Suggest edits' and Content to
 * revisit's tests.
 */
trait SuggestSites
{
    protected function twoSites(): void
    {
        config(['statamic.editions.pro' => true]);

        Site::setSites([
            'default' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_GB'],
            'cy' => ['name' => 'Welsh', 'url' => 'http://localhost/cy/', 'locale' => 'cy_GB'],
        ]);

        Collection::make('site_pages')->title('Pages')->routes('/{slug}')->sites(['default', 'cy'])->save();
        Collection::make('site_journal')->title('Journal')->routes('/journal/{slug}')->dated(true)->sites(['default', 'cy'])->save();

        foreach (['site_pages' => 'site_page', 'site_journal' => 'site_post'] as $collection => $handle) {
            Blueprint::make($handle)->setNamespace('collections.'.$collection)->setContents(['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'summary', 'field' => ['type' => 'textarea', 'display' => 'Summary']],
                ['handle' => 'body', 'field' => ['type' => 'textarea', 'display' => 'Body']],
            ]])->save();
        }
    }

    protected function tearDown(): void
    {
        foreach (['collections.site_pages.site_page', 'collections.site_journal.site_post'] as $blueprint) {
            Blueprint::find($blueprint)?->delete();
        }

        parent::tearDown();
    }
}
