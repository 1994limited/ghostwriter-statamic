<?php

namespace NineteenNinetyFour\Ghostwriter\Images;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Finds free-to-use photographs for a draft, for sites with no image model
 * to call or that would rather use a real photograph.
 *
 * Unsplash, Pexels and Pixabay are searched when the site has a (free) API
 * key for them. Openverse needs no key and is searched for public-domain and CC0
 * work only, so nothing found here carries a condition the site must meet.
 */
class StockSearch
{
    private const PER_SOURCE = 9;

    /** Largest file that will be brought into the asset container. */
    private const MAX_BYTES = 15 * 1024 * 1024;

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * @return array<int, string> The sources that can be searched, best first.
     */
    public function sources(): array
    {
        return array_values(array_filter([
            $this->key('unsplash') ? 'unsplash' : null,
            $this->key('pexels') ? 'pexels' : null,
            $this->key('pixabay') ? 'pixabay' : null,
            config('ghostwriter.images.openverse', true) ? 'openverse' : null,
        ]));
    }

    /**
     * @param  string  $shape  landscape, portrait or square
     * @return array<int, array{source: string, id: string, thumb: string, credit: string, credit_url: ?string, licence: string}>
     */
    public function search(string $query, string $shape = 'landscape'): array
    {
        $results = $this->searchAll($query, $shape);
        $words = preg_split('/\s+/u', trim($query)) ?: [];

        // The smaller libraries match every word, so a long search finds
        // nothing. Its first two words are usually the subject.
        if ($results === [] && count($words) > 2) {
            $results = $this->searchAll(implode(' ', array_slice($words, 0, 2)), $shape);
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchAll(string $query, string $shape): array
    {
        $results = [];

        foreach ($this->sources() as $source) {
            try {
                array_push($results, ...$this->{$source}($query, $shape));
            } catch (\Throwable $exception) {
                // One source being down should not hide the others.
                report($exception);
            }
        }

        return $results;
    }

    /**
     * Download one photograph. The file's address is looked up again from
     * the source by ID, never taken from the browser.
     *
     * @return array{content: string, extension: string, credit: string, credit_url: ?string, licence: string, source: string}
     */
    public function fetch(string $source, string $id): array
    {
        if (! in_array($source, $this->sources(), true) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            throw new InvalidArgumentException('That photograph could not be found.');
        }

        $photo = match ($source) {
            'unsplash' => $this->unsplashPhoto($id),
            'pexels' => $this->pexelsPhoto($id),
            'pixabay' => $this->pixabayPhoto($id),
            default => $this->openversePhoto($id),
        };

        if (! preg_match('#^https://#', (string) $photo['file'])) {
            throw new InvalidArgumentException('That photograph has no secure download address.');
        }

        $response = Http::timeout(60)
            ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']], 'stream' => true])
            ->get($photo['file'])
            ->throw();
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        // Read no more than the cap: a file that is larger is not wanted,
        // so the rest of it is never fetched.
        $body = $response->toPsrResponse()->getBody();
        $content = '';

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (! $body->eof() && strlen($content) <= self::MAX_BYTES) {
            $content .= $body->read(self::MAX_BYTES + 1 - strlen($content));
        }

        if (! isset(self::EXTENSIONS[$mime]) || strlen($content) > self::MAX_BYTES || @getimagesizefromstring($content) === false) {
            throw new InvalidArgumentException('That file is not an image Ghostwriter can use.');
        }

        unset($photo['file']);

        return $photo + ['content' => $content, 'extension' => self::EXTENSIONS[$mime], 'source' => $source];
    }

    private function key(string $source): ?string
    {
        $key = config("ghostwriter.images.{$source}_key");

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function unsplash(string $query, string $shape): array
    {
        $photos = Http::timeout(20)->withHeaders(['Authorization' => 'Client-ID '.$this->key('unsplash'), 'Accept-Version' => 'v1'])
            ->get('https://api.unsplash.com/search/photos', [
                'query' => $query,
                'per_page' => self::PER_SOURCE,
                'orientation' => $shape === 'square' ? 'squarish' : $shape,
                'content_filter' => 'high',
            ])->throw()->json('results', []);

        return array_map(fn (array $photo) => [
            'source' => 'unsplash',
            'id' => (string) $photo['id'],
            'thumb' => (string) $photo['urls']['small'],
            'credit' => ($photo['user']['name'] ?? 'Unknown').' on Unsplash',
            'credit_url' => $photo['links']['html'] ?? null,
            'licence' => 'Unsplash licence',
        ], $photos);
    }

    /**
     * @return array<string, mixed>
     */
    private function unsplashPhoto(string $id): array
    {
        $client = Http::timeout(20)->withHeaders(['Authorization' => 'Client-ID '.$this->key('unsplash'), 'Accept-Version' => 'v1']);
        $photo = $client->get("https://api.unsplash.com/photos/{$id}")->throw()->json();

        // Unsplash asks to be told when a photograph is actually used.
        if (! empty($photo['links']['download_location'])) {
            rescue(fn () => $client->get($photo['links']['download_location']), report: false);
        }

        return [
            'file' => $photo['urls']['raw'].'&w=2400&fm=jpg&q=82',
            'credit' => ($photo['user']['name'] ?? 'Unknown').' on Unsplash',
            'credit_url' => $photo['links']['html'] ?? null,
            'licence' => 'Unsplash licence',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pexels(string $query, string $shape): array
    {
        $photos = Http::timeout(20)->withHeaders(['Authorization' => $this->key('pexels')])
            ->get('https://api.pexels.com/v1/search', ['query' => $query, 'per_page' => self::PER_SOURCE, 'orientation' => $shape])
            ->throw()->json('photos', []);

        return array_map(fn (array $photo) => [
            'source' => 'pexels',
            'id' => (string) $photo['id'],
            'thumb' => (string) $photo['src']['medium'],
            'credit' => ($photo['photographer'] ?? 'Unknown').' on Pexels',
            'credit_url' => $photo['url'] ?? null,
            'licence' => 'Pexels licence',
        ], $photos);
    }

    /**
     * @return array<string, mixed>
     */
    private function pexelsPhoto(string $id): array
    {
        $photo = Http::timeout(20)->withHeaders(['Authorization' => $this->key('pexels')])
            ->get("https://api.pexels.com/v1/photos/{$id}")->throw()->json();

        return [
            'file' => $photo['src']['large2x'] ?? $photo['src']['original'],
            'credit' => ($photo['photographer'] ?? 'Unknown').' on Pexels',
            'credit_url' => $photo['url'] ?? null,
            'licence' => 'Pexels licence',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pixabay(string $query, string $shape): array
    {
        $photos = Http::timeout(20)->get('https://pixabay.com/api/', [
            'key' => $this->key('pixabay'),
            'q' => $query,
            'image_type' => 'photo',
            'per_page' => self::PER_SOURCE,
            'orientation' => $shape === 'portrait' ? 'vertical' : ($shape === 'landscape' ? 'horizontal' : 'all'),
            'safesearch' => 'true',
        ])->throw()->json('hits', []);

        return array_map(fn (array $photo) => [
            'source' => 'pixabay',
            'id' => (string) $photo['id'],
            'thumb' => (string) $photo['webformatURL'],
            'credit' => ($photo['user'] ?? 'Unknown').' on Pixabay',
            'credit_url' => $photo['pageURL'] ?? null,
            'licence' => 'Pixabay licence',
        ], $photos);
    }

    /**
     * @return array<string, mixed>
     */
    private function pixabayPhoto(string $id): array
    {
        $photo = Http::timeout(20)->get('https://pixabay.com/api/', ['key' => $this->key('pixabay'), 'id' => $id])->throw()->json('hits.0')
            ?? throw new InvalidArgumentException('That photograph could not be found.');

        return [
            'file' => $photo['largeImageURL'] ?? $photo['webformatURL'],
            'credit' => ($photo['user'] ?? 'Unknown').' on Pixabay',
            'credit_url' => $photo['pageURL'] ?? null,
            'licence' => 'Pixabay licence',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function openverse(string $query, string $shape): array
    {
        $photos = Http::timeout(20)->get('https://api.openverse.org/v1/images/', [
            'q' => $query,
            'page_size' => self::PER_SOURCE,
            'license' => 'cc0,pdm',
            'extension' => 'jpg,png',
            'aspect_ratio' => ['landscape' => 'wide', 'portrait' => 'tall', 'square' => 'square'][$shape] ?? 'wide',
            'mature' => 'false',
        ])->throw()->json('results', []);

        return array_map(fn (array $photo) => [
            'source' => 'openverse',
            'id' => (string) $photo['id'],
            'thumb' => (string) ($photo['thumbnail'] ?? $photo['url']),
            'credit' => trim(($photo['creator'] ?? '') ?: 'Unknown').' via Openverse',
            'credit_url' => $photo['foreign_landing_url'] ?? null,
            'licence' => strtoupper((string) ($photo['license'] ?? 'cc0')) === 'PDM' ? 'Public domain' : 'CC0',
        ], $photos);
    }

    /**
     * @return array<string, mixed>
     */
    private function openversePhoto(string $id): array
    {
        $photo = Http::timeout(20)->get("https://api.openverse.org/v1/images/{$id}/")->throw()->json();

        if (! in_array($photo['license'] ?? null, ['cc0', 'pdm'], true)) {
            throw new InvalidArgumentException('That photograph is not free of conditions.');
        }

        return [
            'file' => $photo['url'],
            'credit' => trim(($photo['creator'] ?? '') ?: 'Unknown').' via Openverse',
            'credit_url' => $photo['foreign_landing_url'] ?? null,
            'licence' => $photo['license'] === 'pdm' ? 'Public domain' : 'CC0',
        ];
    }
}
