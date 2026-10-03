<?php

namespace NineteenNinetyFour\Ghostwriter\Preview;

use Facades\Statamic\CP\LivePreview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewData;
use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewMarkers;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Drafts\BuiltValues;
use NineteenNinetyFour\Ghostwriter\Stock\StandInComps;
use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Token;
use Statamic\Facades\URL;
use Statamic\Fields\Blueprint;
use Throwable;

/**
 * Renders an unsaved draft through the site's own templates, with
 * Statamic's Live Preview (design §7.2). Nothing about the entry is saved:
 * the only writes are Live Preview's token file and its cached item, and
 * both expire.
 *
 * - The data is apply's (DraftValues), with the preview's invisible
 *   markers added (PreviewMarkers) to this copy only.
 * - An existing entry is a fresh copy with the data as supplements, as
 *   Statamic's own Live Preview does. A new one is a PreviewEntry with a
 *   preview-only id and the URI it will have.
 * - The item carries `live_preview.ghostwriter`, which templates can test
 *   (`{{ live_preview:ghostwriter }}`) and the front-end middleware uses
 *   to know a Ghostwriter render (GhostwriterPreviewResponse).
 * - Renders are kept by what they show (PreviewData's hash, plus where the
 *   page goes), for ten minutes, a few per session: the same draft gets the
 *   same address back, with no new token and no new render. Tokens dropped
 *   from the list, and those that have expired, are deleted.
 */
class PagePreview
{
    /** How long a render's address is reused for the same draft. */
    public const REUSE_SECONDS = 600;

    /** Renders kept per session; older ones lose their token. */
    public const KEEP = 4;

    public function __construct(private StandInComps $standIns) {}

    /**
     * @param  array<string, mixed>  $form  The publish form's values (slug, parent, date).
     * @return array{available: bool, url?: string, map?: array<int, array<string, mixed>>, hash?: string, title_key?: ?string, expires?: string, cached?: bool, same_origin?: bool}
     */
    public function render(Session $session, Draft $draft, Blueprint $blueprint, BuiltValues $built, ?Entry $original, array $form, string $site, string $origin): array
    {
        $collection = $original?->collection() ?? $blueprint->parent();

        if (! $collection instanceof Collection || ! $collection->route($site)) {
            return ['available' => false];
        }

        $preview = $this->mark($session, $draft, $built);
        $slug = $this->slug($original, $form, $draft);
        $parent = self::parent($form);

        $key = sha1(implode('|', [$preview->hash, $blueprint->handle(), $site, (string) $original?->id(), $slug, (string) $parent, json_encode($form['date'] ?? null)]));
        $map = $this->withStandIns($preview->map->toArray());
        $title = collect($map)->first(fn (array $block) => $block['kind'] === 'field' && $block['type'] === 'title')['key'] ?? null;

        if ($kept = $this->kept($session->id, $key)) {
            return ['available' => true, 'url' => $kept['url'], 'map' => $map, 'hash' => $preview->hash, 'title_key' => $title, 'expires' => $kept['expires'], 'cached' => true, 'same_origin' => self::sameOrigin($kept['url'], $origin)];
        }

        $entry = $original
            ? $this->existing($original, $preview->data)
            : $this->unsaved($collection, $blueprint, $preview->data, $slug, $parent, $form, $site);

        $entry->setSupplement('live_preview', ['ghostwriter' => true]);

        $target = $entry->previewTargets()->first()['url'] ?? null;

        if (! is_string($target) || $target === '') {
            return ['available' => false];
        }

        $token = LivePreview::tokenize(null, $entry);
        $url = URL::makeAbsolute(PreviewMarkers::stripText($target));
        $url .= (str_contains($url, '?') ? '&' : '?').'live-preview='.Str::random(16).'&token='.$token->token();
        $expires = $token->expiry()->toIso8601String();

        $this->keep($session->id, $key, $token->token(), $url, $expires);

        return ['available' => true, 'url' => $url, 'map' => $map, 'hash' => $preview->hash, 'title_key' => $title, 'expires' => $expires, 'cached' => false, 'same_origin' => self::sameOrigin($url, $origin)];
    }

    /**
     * The preview's copy of the data, marked, with the draft's units, and
     * Bard's sets as blocks of their own.
     */
    public function mark(Session $session, Draft $draft, BuiltValues $built): PreviewData
    {
        $units = Units::fromDraft($draft, $built->schema);

        if ($session->units !== []) {
            $units = $units->restore($session->units);
        }

        // Core marks the text and sections; Bard's sets are mapped here.
        return (new BardSetMarkers)->mark((new PreviewMarkers)->mark($built->data, $built->schema, $units), $built->schema);
    }

    /**
     * A stock stand-in shows as its comp to signed-in editors, at the comp's
     * own address: the stock record's id is listed beside the file name so
     * the locator still finds the image by its address.
     *
     * @param  array<int, array<string, mixed>>  $map
     * @return array<int, array<string, mixed>>
     */
    private function withStandIns(array $map): array
    {
        if (! config('ghostwriter.stock.live_preview', true) || ! collect($map)->contains(fn (array $block) => ! empty($block['assets']))) {
            return $map;
        }

        try {
            $comps = $this->standIns->all();
        } catch (Throwable) {
            return $map;
        }

        foreach ($map as $i => $block) {
            foreach ($block['assets'] ?? [] as $asset) {
                if (isset($comps[$asset])) {
                    $map[$i]['assets'][] = $comps[$asset]['id'];
                }
            }
        }

        return $map;
    }

    /**
     * Every token the session's renders hold, deleted: when the session is.
     */
    public function forget(string $sessionId): void
    {
        foreach ($this->renders($sessionId) as $render) {
            self::deleteToken($render['token']);
        }

        Cache::forget(self::cacheKey($sessionId));
    }

    /**
     * A fresh copy of the entry being edited, with the draft as supplements.
     *
     * @param  array<string, mixed>  $data
     */
    private function existing(Entry $original, array $data): Entry
    {
        $entry = clone $original;

        foreach ($data as $handle => $value) {
            $entry->setSupplement($handle, $value);
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $form
     */
    private function unsaved(Collection $collection, Blueprint $blueprint, array $data, string $slug, ?string $parent, array $form, string $site): PreviewEntry
    {
        $entry = (new PreviewEntry)
            ->id(PreviewEntry::newId())
            ->collection($collection)
            ->blueprint($blueprint->handle())
            ->locale($site)
            ->slug($slug)
            ->published(false)
            ->previewParent($parent)
            ->data($data);

        if ($collection->dated()) {
            $entry->date($this->date($form['date'] ?? null));
        }

        return $entry;
    }

    /**
     * The slug the form holds, else the entry's own, else one from the title.
     *
     * @param  array<string, mixed>  $form
     */
    private function slug(?Entry $original, array $form, Draft $draft): string
    {
        $slug = is_string($form['slug'] ?? null) ? trim($form['slug']) : '';

        if ($slug === '' && $original) {
            $slug = (string) $original->slug();
        }

        return $slug !== '' ? Str::slug($slug) : (Str::slug($draft->title()) ?: 'preview');
    }

    /**
     * The parent the form chose, in a structured collection.
     *
     * @param  array<string, mixed>  $form
     */
    private static function parent(array $form): ?string
    {
        $parent = $form['parent'] ?? null;
        $parent = is_array($parent) ? ($parent[0] ?? null) : $parent;

        return is_scalar($parent) && (string) $parent !== '' ? (string) $parent : null;
    }

    private function date(mixed $value): Carbon
    {
        // The date field sends {date, time} or a string; anything else is today.
        $value = is_array($value) ? trim(($value['date'] ?? '').' '.($value['time'] ?? '')) : $value;

        try {
            return is_string($value) && trim($value) !== '' ? Carbon::parse($value) : now();
        } catch (Throwable) {
            return now();
        }
    }

    private static function sameOrigin(string $url, string $origin): bool
    {
        $parts = parse_url($url);

        if (! isset($parts['host'])) {
            return true;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower(($parts['scheme'] ?? 'https').'://'.$parts['host'].$port) === strtolower(rtrim($origin, '/'));
    }

    /**
     * A render of this session's kept under the same key, while its token holds.
     *
     * @return array{token: string, url: string, expires: string, at: int}|null
     */
    private function kept(string $sessionId, string $key): ?array
    {
        $render = $this->renders($sessionId)[$key] ?? null;

        if (! $render || $render['at'] < time() - self::REUSE_SECONDS || ! Token::find($render['token']) || ! Cache::has('statamic.live-preview.'.$render['token'])) {
            return null;
        }

        return $render;
    }

    private function keep(string $sessionId, string $key, string $token, string $url, string $expires): void
    {
        $renders = $this->renders($sessionId);
        $previous = $renders[$key]['token'] ?? null;

        unset($renders[$key]);
        $renders[$key] = ['token' => $token, 'url' => $url, 'expires' => $expires, 'at' => time()];

        if ($previous !== null && $previous !== $token) {
            self::deleteToken($previous);
        }

        // Too old to reuse, or beyond the few kept: their tokens go now.
        foreach ($renders as $old => $render) {
            if (count($renders) > self::KEEP || $render['at'] < time() - self::REUSE_SECONDS) {
                self::deleteToken($render['token']);
                unset($renders[$old]);
            }
        }

        Cache::put(self::cacheKey($sessionId), $renders, now()->addHour());
    }

    /**
     * @return array<string, array{token: string, url: string, expires: string, at: int}>
     */
    private function renders(string $sessionId): array
    {
        $renders = Cache::get(self::cacheKey($sessionId));

        return is_array($renders) ? $renders : [];
    }

    private static function deleteToken(string $token): void
    {
        try {
            Token::find($token)?->delete();
        } catch (Throwable) {
            // Gone already.
        }

        Cache::forget('statamic.live-preview.'.$token);
    }

    private static function cacheKey(string $sessionId): string
    {
        return 'ghostwriter.preview.'.$sessionId;
    }
}
