<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Priority;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitReason;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitRow;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Jobs\RefreshRevisit;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Suggest\Revisit;
use NineteenNinetyFour\Ghostwriter\Suggest\SuggestEdits;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Content to revisit: published pages ranked by free checks (dates,
 * links, alt text, empty fields and age), with their reasons and a Review
 * button that opens the page with Suggest edits ready to run. No model,
 * ever; the list is kept current on save and by the daily pass.
 */
class RevisitController
{
    public const PER_PAGE = 25;

    /** The tiles, and the reason kinds each filters the list to. */
    public const TILES = [
        'worth-a-look' => [],
        'past-year' => ['past-year', 'relative-time', 'closing-date'],
        'broken-link' => ['broken-link', 'external-link'],
        'missing-alt' => ['missing-alt'],
    ];

    public function show(Request $request, RevisitStore $rows, Revisit $revisit, TypeRepository $types, SuggestEdits $suggest, Settings $settings): Response
    {
        $site = Site::selected()?->handle() ?? Site::default()->handle();
        $now = Carbon::now()->toDateTimeImmutable();
        $tile = array_key_exists((string) $request->query('show'), self::TILES) ? (string) $request->query('show') : null;
        $group = $this->group($request, $types);
        $kinds = $tile !== null ? self::TILES[$tile] : [];
        $page = max(1, (int) $request->query('page', 1));
        $last = $revisit->lastRun($site);

        // Never read yet (no scheduler run): read every page once, queued.
        if ($last === null) {
            RefreshRevisit::start();
        }

        $visible = $this->visibleGroups($types);
        $listed = array_values(array_filter($rows->top($site, $group, 2000, 0, $kinds, $now), fn (RevisitRow $row) => in_array($row->entry->group, $visible, true) && ($tile !== 'worth-a-look' || $row->score >= Priority::WORTH_A_LOOK)));
        $stats = $rows->stats($site, $now);

        return Inertia::render('ghostwriter::Revisit', [
            'tiles' => array_map(fn (string $key) => [
                'key' => $key,
                'count' => $key === 'worth-a-look' ? (int) ($stats['worth-a-look'] ?? 0) : array_sum(array_map(fn (string $kind) => (int) ($stats[$kind] ?? 0), self::TILES[$key])),
                'label' => $suggest->text(new Message('revisit.tile.'.$key)),
                'url' => cp_route('ghostwriter.revisit.show', array_filter(['show' => $key === $tile ? null : $key, 'collection' => $group])),
            ], array_keys(self::TILES)),
            'tile' => $tile,
            'collection' => $group,
            'collections' => $types->collections()->filter(fn ($collection) => in_array($collection->handle(), $visible, true))->map(fn ($collection) => [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'url' => cp_route('ghostwriter.revisit.show', array_filter(['show' => $tile, 'collection' => $collection->handle()])),
            ])->values(),
            'all_url' => cp_route('ghostwriter.revisit.show', array_filter(['show' => $tile])),
            'rows' => array_map(fn (RevisitRow $row) => $this->row($row, $suggest, $types), array_slice($listed, ($page - 1) * self::PER_PAGE, self::PER_PAGE)),
            'page' => $page,
            'pages' => max(1, (int) ceil(count($listed) / self::PER_PAGE)),
            'total' => count($listed),
            'note' => __('Found without AI: dates, links, alt text, empty fields and age. Reviewing a page uses Ghostwriter, only when you ask.'),
            'empty' => $suggest->text(new Message('revisit.empty')),
            'reading' => $last === null,
            'last_run' => $last ? Carbon::instance($last)->diffForHumans() : null,
            'external_links' => $settings->checksExternalLinks(),
            'settings_url' => $settings->urlForCurrentUser(),
            'snooze_url' => cp_route('ghostwriter.revisit.snooze'),
        ]);
    }

    /** Snooze for 90 days: off the list for the site, for everyone. */
    public function snooze(Request $request, RevisitStore $rows): JsonResponse
    {
        $request->validate(['key' => ['required', 'string', 'max:500']]);

        [$ref, $row] = $this->found($rows, (string) $request->input('key'));
        $entry = Entry::find((string) $ref->id);

        abort_unless($entry && User::current()?->can('edit', $entry), 403);

        $rows->put($row->snooze(Carbon::now()->toDateTimeImmutable()));

        return response()->json(['snoozed' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(RevisitRow $row, SuggestEdits $suggest, TypeRepository $types): array
    {
        $reasons = $row->reasons;
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($reasons, fn (RevisitReason $a, RevisitReason $b) => $order[$a->severity()] <=> $order[$b->severity()]);

        return [
            'key' => $row->entry->key(),
            'title' => $row->title,
            'collection' => $types->collections()->first(fn ($collection) => $collection->handle() === $row->entry->group)?->title() ?? $row->entry->group,
            'updated' => $row->updatedAt ? Carbon::parse($row->updatedAt)->isoFormat('MMM YYYY') : null,
            'updated_iso' => $row->updatedAt,
            'reasons' => array_map(fn (RevisitReason $reason) => ['kind' => $reason->kind->value, 'severity' => $reason->severity(), 'text' => $suggest->text($reason->message())], $reasons),
            'score' => $row->score,
            'priority' => $row->priority(),
            'priority_label' => $suggest->text(new Message('revisit.priority.'.Priority::word($row->score))),
            'edit_url' => $row->editUrl,
            'review_url' => $row->editUrl ? $row->editUrl.(str_contains($row->editUrl, '?') ? '&' : '?').'ghostwriter=suggest' : null,
        ];
    }

    /**
     * @return array{EntryRef, RevisitRow}
     */
    private function found(RevisitStore $rows, string $key): array
    {
        if (preg_match('/^([^:]+):(.+?)(?:@(.+))?$/', $key, $m) !== 1) {
            abort(404);
        }

        $ref = new EntryRef($m[1], $m[2], $m[3] ?? null);
        $row = $rows->get($ref);

        abort_unless($row, 404);

        return [$ref, $row];
    }

    private function group(Request $request, TypeRepository $types): ?string
    {
        $group = $request->query('collection');

        return is_string($group) && $types->enabled($group) ? $group : null;
    }

    /**
     * Collections whose entries this person may see.
     *
     * @return list<string>
     */
    private function visibleGroups(TypeRepository $types): array
    {
        $user = User::current();

        return $types->collections()->filter(fn ($collection) => $user?->can('view '.$collection->handle().' entries'))->map->handle()->values()->all();
    }
}
