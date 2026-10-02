<?php

namespace NineteenNinetyFour\Ghostwriter\Stock;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoContext;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Core's StockSearch over the libraries "Search in" chose: the free ones,
 * one paid library, or everything. For everything, each library's
 * results take turns (best first from each), so a paid library isn't
 * pushed out of the shortlist by the free ones before it; the free
 * libraries alone come one after another, as they always have. Editorial
 * images are left out unless asked for, whatever the library does with
 * the request.
 */
class ScopedStockSearch extends StockSearch
{
    /**
     * @param  array<int, string>  $scope  The library IDs to search.
     * @param  array<int, PhotoLibrary>  $libraries
     */
    public function __construct(
        HttpClients $http,
        Credentials $credentials,
        bool|\Closure $openverse,
        ?LoggerInterface $logger,
        array $libraries,
        private readonly array $scope,
        private readonly bool $editorial = false,
        private readonly bool $interleave = false,
        private readonly ?LoggerInterface $log = null,
    ) {
        parent::__construct($http, $credentials, $openverse, $logger, $libraries);
    }

    public function sources(): array
    {
        return array_values(array_filter(parent::sources(), fn (string $source) => in_array($source, $this->scope, true)));
    }

    public function search(string $query, Shape|string $shape = Shape::Landscape, int $perSource = self::PER_SOURCE, ?array $sources = null): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $sources = $sources === null ? $this->sources() : array_values(array_intersect($this->sources(), $sources));
        $shape = PhotoContext::shape($shape);
        $results = $this->interleaved(new SearchQuery($query, $shape, perPage: $perSource, editorial: $this->editorial), $sources);
        $words = preg_split('/\s+/u', $query) ?: [];

        if ($results === [] && count($words) > 2) {
            $results = $this->interleaved(new SearchQuery(implode(' ', array_slice($words, 0, 2)), $shape, perPage: $perSource, editorial: $this->editorial), $sources);
        }

        return array_map(fn (Photo $photo) => $photo->withTerm($query), $results);
    }

    /**
     * @param  array<int, string>  $sources
     * @return array<int, Photo>
     */
    private function interleaved(SearchQuery $query, array $sources): array
    {
        $lists = [];

        foreach ($sources as $source) {
            try {
                $found = $this->library($source)?->search($query) ?? [];
                $lists[] = array_values(array_filter($found, fn (Photo $photo) => $this->editorial || ! $photo->editorial));
            } catch (Throwable $exception) {
                $this->log?->warning("Ghostwriter: searching {$source} failed: {$exception->getMessage()}", ['source' => $source]);
            }
        }

        if (! $this->interleave || count($lists) === 1) {
            return array_merge(...$lists);
        }

        $results = [];

        for ($i = 0; $lists !== []; $i++) {
            foreach ($lists as $n => $list) {
                if (! isset($list[$i])) {
                    unset($lists[$n]);

                    continue;
                }

                $results[] = $list[$i];
            }
        }

        return $results;
    }
}
