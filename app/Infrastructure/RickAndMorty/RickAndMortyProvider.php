<?php

declare(strict_types=1);

namespace App\Infrastructure\RickAndMorty;

use App\Domain\Catalog\Contracts\CatalogProvider;
use App\Domain\Catalog\Data\ResourcePage;
use App\Domain\Catalog\Exceptions\CatalogUnavailable;
use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;
use App\Infrastructure\RickAndMorty\Mappers\CharacterMapper;
use App\Infrastructure\RickAndMorty\Mappers\EpisodeMapper;
use App\Infrastructure\RickAndMorty\Mappers\LocationMapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The only class in the application that knows Rick and Morty exists.
 *
 * Everything it returns is a domain object and everything it throws is a domain
 * exception, so nothing above this line has to know about HTTP status codes,
 * JSON shapes or pagination URLs.
 */
final class RickAndMortyProvider implements CatalogProvider
{
    public function __construct(
        private readonly PayloadValidator $validator,
        private readonly CharacterMapper $characters,
        private readonly EpisodeMapper $episodes,
        private readonly LocationMapper $locations,
    ) {}

    public function fetchCharacters(?string $cursor = null): ResourcePage
    {
        return $this->fetch('character', $cursor, fn (array $record) => $this->characters->map($record));
    }

    public function fetchEpisodes(?string $cursor = null): ResourcePage
    {
        return $this->fetch('episode', $cursor, fn (array $record) => $this->episodes->map($record));
    }

    public function fetchLocations(?string $cursor = null): ResourcePage
    {
        return $this->fetch('location', $cursor, fn (array $record) => $this->locations->map($record));
    }

    /**
     * @param  callable(array<string, mixed>): object  $map
     *
     * @throws CatalogUnavailable
     * @throws MalformedCatalogPayload
     */
    private function fetch(string $resource, ?string $cursor, callable $map): ResourcePage
    {
        $body = $this->request($resource, $cursor)->json();

        $page = $this->validator->validatePage($resource, $body);

        return new ResourcePage(
            items: array_values(array_map($map, $page['results'])),
            nextCursor: $page['next'],
            totalCount: $page['count'],
        );
    }

    /**
     * @throws CatalogUnavailable
     */
    private function request(string $resource, ?string $cursor): Response
    {
        $attempts = max(1, (int) config('rickandmorty.retry_times'));
        $delay = max(0, (int) config('rickandmorty.retry_delay'));

        // The provider paces itself. Its rate limit is a property of this
        // provider, not of whoever is calling, so callers never have to know
        // about it — and code above the port never has to read a config key
        // named after a vendor in order to be well behaved.
        $this->pause();

        try {
            $response = Http::acceptJson()
                ->connectTimeout((int) config('rickandmorty.connect_timeout'))
                ->timeout((int) config('rickandmorty.timeout'))
                ->retry(
                    times: $attempts,
                    sleepMilliseconds: fn (int $attempt): int => $delay * (2 ** ($attempt - 1)),
                    when: fn (Throwable $failure): bool => $this->isTransient($failure),
                    // Failures are turned into domain exceptions below rather
                    // than allowed to escape as framework ones. Nothing above
                    // this class should have to catch an Illuminate exception.
                    throw: false,
                )
                ->get($this->url($resource, $cursor));
        } catch (ConnectionException $failure) {
            throw CatalogUnavailable::afterRetries($resource, $attempts, $failure);
        }

        if ($response->failed()) {
            throw CatalogUnavailable::respondedWith($resource, $response->status());
        }

        return $response;
    }

    /**
     * Only failures that can resolve themselves are worth another attempt.
     *
     * A 404 or a 422 is an answer, not a fault: asking again produces the same
     * answer more slowly. A 429 is the one exception among the 4xx — it is the
     * server explicitly asking to be called later, and this provider does use
     * it: thirty-odd rapid requests are enough to trigger its rate limit.
     */
    private function isTransient(Throwable $failure): bool
    {
        if ($failure instanceof ConnectionException) {
            return true;
        }

        if (! $failure instanceof RequestException) {
            return false;
        }

        $status = $failure->response->status();

        return $status === 429 || $status >= 500;
    }

    private function pause(): void
    {
        $milliseconds = max(0, (int) config('rickandmorty.page_delay'));

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * A cursor is used verbatim: it is the URL the provider handed back, and
     * the validator already confirmed it still points at the configured host
     * before letting it out of the previous page.
     */
    private function url(string $resource, ?string $cursor): string
    {
        if ($cursor !== null) {
            return $cursor;
        }

        return rtrim((string) config('rickandmorty.api_url'), '/').'/'.$resource;
    }
}
