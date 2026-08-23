<?php

declare(strict_types=1);

namespace App\Infrastructure\RickAndMorty;

use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;

/**
 * Guards the shape of a page returned by the Rick and Morty API.
 *
 * Validates the envelope only — `info` and `results`. Whether an individual
 * record carries the fields its data object needs is each mapper's business,
 * because each mapper is the one that knows what it needs.
 *
 * What is fatal and what degrades follows a single rule: strict about anything
 * that drives control flow, tolerant about anything merely informative.
 */
final class PayloadValidator
{
    /**
     * @param  string  $resource  Resource name, used only to build error messages.
     * @param  mixed  $body  The decoded response body.
     * @return array{results: list<array<string, mixed>>, next: string|null, count: int}
     *
     * @throws MalformedCatalogPayload
     */
    public function validatePage(string $resource, mixed $body): array
    {
        if (! is_array($body)) {
            throw MalformedCatalogPayload::unexpectedStructure($resource, 'the body is not a JSON object');
        }

        $results = $this->validateResults($resource, $body);
        $info = $this->validateInfo($resource, $body);

        return [
            'results' => $results,
            'next' => $this->validateNext($resource, $info),
            'count' => is_numeric($info['count'] ?? null) ? (int) $info['count'] : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     *
     * @throws MalformedCatalogPayload
     */
    private function validateResults(string $resource, array $body): array
    {
        if (! array_key_exists('results', $body)) {
            throw MalformedCatalogPayload::missingField($resource, 'results');
        }

        if (! is_array($body['results'])) {
            throw MalformedCatalogPayload::unexpectedStructure($resource, '"results" is not a list');
        }

        foreach ($body['results'] as $position => $record) {
            if (! is_array($record)) {
                throw MalformedCatalogPayload::unexpectedStructure(
                    $resource,
                    sprintf('entry %s of "results" is not an object', (string) $position),
                );
            }
        }

        return array_values($body['results']);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws MalformedCatalogPayload
     */
    private function validateInfo(string $resource, array $body): array
    {
        if (! array_key_exists('info', $body)) {
            throw MalformedCatalogPayload::missingField($resource, 'info');
        }

        if (! is_array($body['info'])) {
            throw MalformedCatalogPayload::unexpectedStructure($resource, '"info" is not an object');
        }

        return $body['info'];
    }

    /**
     * @param  array<string, mixed>  $info
     *
     * @throws MalformedCatalogPayload
     */
    private function validateNext(string $resource, array $info): ?string
    {
        // array_key_exists, never isset: on the last page `next` is null, and
        // isset() cannot tell a null value from an absent key. Reading an absent
        // key as "no more pages" would truncate the catalogue in silence, which
        // is worse than failing outright.
        if (! array_key_exists('next', $info)) {
            throw MalformedCatalogPayload::missingField($resource, 'info.next');
        }

        $next = $info['next'];

        if ($next === null) {
            return null;
        }

        if (! is_string($next)) {
            throw MalformedCatalogPayload::unexpectedStructure($resource, '"info.next" is neither a string nor null');
        }

        // The next page is requested from a URL chosen by a third party. Confirm
        // it still points at the configured host before following it.
        $apiUrl = rtrim((string) config('rickandmorty.api_url'), '/');

        if (! str_starts_with($next, $apiUrl)) {
            throw MalformedCatalogPayload::unexpectedStructure(
                $resource,
                '"info.next" points outside the configured catalog host',
            );
        }

        return $next;
    }
}
