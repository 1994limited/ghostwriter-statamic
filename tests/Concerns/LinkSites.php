<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Concerns;

use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Beside SuggestSites' Pages (Ghostwriter's) and Journal: two collections
 * Ghostwriter doesn't write for, with routes, for the link index: Info
 * (About, Contact…; with a noindex toggle) and dated Events whose future
 * entries are private (scheduled).
 */
trait LinkSites
{
    use SuggestSites;

    protected function linkSites(): void
    {
        $this->twoSites();

        config(['ghostwriter.collections' => ['site_pages'], 'ghostwriter.revisit.on_save' => true]);

        Collection::make('site_info')->title('Information')->routes('/{slug}')->sites(['default', 'cy'])->save();
        Collection::make('site_events')->title('Events')->routes('/events/{slug}')->dated(true)->futureDateBehavior('private')->sites(['default', 'cy'])->save();

        foreach (['site_info' => 'site_info_page', 'site_events' => 'site_event'] as $collection => $handle) {
            Blueprint::make($handle)->setNamespace('collections.'.$collection)->setContents(['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'summary', 'field' => ['type' => 'textarea', 'display' => 'Summary']],
                ['handle' => 'body', 'field' => ['type' => 'textarea', 'display' => 'Body']],
                ['handle' => 'noindex', 'field' => ['type' => 'toggle', 'display' => 'Hide from search engines']],
            ]])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveEntry(string $id, string $collection, string $title, array $data = [], string $site = 'default', bool $published = true, ?string $date = null, ?string $slug = null): void
    {
        $entry = Entry::make()->id($id)->collection($collection)->locale($site)->slug($slug ?? $id)->published($published)->data(['title' => $title, ...$data]);

        if ($date !== null) {
            $entry->date($date);
        }

        $entry->save();
    }

    protected function tearDownLinkSites(): void
    {
        foreach (['collections.site_info.site_info_page', 'collections.site_events.site_event'] as $blueprint) {
            Blueprint::find($blueprint)?->delete();
        }
    }
}
