<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\RickAndMorty;

use App\Domain\Catalog\Data\CharacterData;
use App\Domain\Catalog\Exceptions\CatalogUnavailable;
use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;
use App\Infrastructure\RickAndMorty\RickAndMortyProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every request is faked and `preventStrayRequests` turns any unfaked call into
 * a failure, so this suite cannot reach the network even by accident.
 *
 * The retry delay is set to zero here. What is under test is which failures are
 * retried and how many times, not how long the client waits between attempts.
 */
final class RickAndMortyProviderTest extends TestCase
{
    private const API = 'https://rickandmortyapi.com/api';

    private RickAndMortyProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rickandmorty.api_url', self::API);
        config()->set('rickandmorty.connect_timeout', 2);
        config()->set('rickandmorty.timeout', 5);
        config()->set('rickandmorty.retry_times', 3);
        config()->set('rickandmorty.retry_delay', 0);

        Http::preventStrayRequests();

        $this->provider = app(RickAndMortyProvider::class);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function page(array $results = [], ?string $next = null, int $count = 0): array
    {
        return [
            'info' => ['count' => $count, 'pages' => 1, 'next' => $next, 'prev' => null],
            'results' => $results,
        ];
    }

    public function test_it_returns_domain_objects_not_provider_payloads(): void
    {
        Http::fake(['*' => Http::response($this->page([
            ['id' => 1, 'name' => 'Rick Sanchez', 'status' => 'Alive', 'species' => 'Human'],
        ], null, 826))]);

        $page = $this->provider->fetchCharacters();

        $this->assertInstanceOf(CharacterData::class, $page->items[0]);
        $this->assertSame('Rick Sanchez', $page->items[0]->name);
        $this->assertSame(826, $page->totalCount);
        $this->assertNull($page->nextCursor);
        $this->assertFalse($page->hasMore());
    }

    public function test_it_starts_from_the_configured_base_url_when_there_is_no_cursor(): void
    {
        Http::fake(['*' => Http::response($this->page())]);

        $this->provider->fetchCharacters();

        Http::assertSent(fn ($request) => $request->url() === self::API.'/character');
    }

    public function test_it_uses_the_cursor_verbatim_as_the_next_url(): void
    {
        Http::fake(['*' => Http::response($this->page())]);

        $this->provider->fetchCharacters(self::API.'/character?page=2');

        Http::assertSent(fn ($request) => $request->url() === self::API.'/character?page=2');
    }

    public function test_it_hands_back_the_cursor_for_the_following_page(): void
    {
        Http::fake(['*' => Http::response($this->page([], self::API.'/character?page=2', 826))]);

        $page = $this->provider->fetchCharacters();

        $this->assertSame(self::API.'/character?page=2', $page->nextCursor);
        $this->assertTrue($page->hasMore());
    }

    public function test_it_asks_the_right_url_for_each_resource(): void
    {
        Http::fake(['*' => Http::response($this->page())]);

        $this->provider->fetchEpisodes();
        $this->provider->fetchLocations();

        Http::assertSent(fn ($request) => $request->url() === self::API.'/episode');
        Http::assertSent(fn ($request) => $request->url() === self::API.'/location');
    }

    public function test_it_retries_a_server_error_and_succeeds(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('upstream exploded', 500)
            ->push($this->page([['id' => 1, 'name' => 'Rick Sanchez']])),
        ]);

        $page = $this->provider->fetchCharacters();

        $this->assertCount(1, $page->items);
        Http::assertSentCount(2);
    }

    /**
     * The provider does rate limit in practice: around thirty rapid requests
     * are enough. A 429 is the one 4xx worth asking again.
     */
    public function test_it_retries_a_rate_limited_response(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('error code: 1015', 429)
            ->push($this->page()),
        ]);

        $this->provider->fetchCharacters();

        Http::assertSentCount(2);
    }

    /**
     * A 404 is an answer, not a fault. Asking again returns the same 404 more
     * slowly, so exactly one request must leave.
     */
    public function test_it_does_not_retry_a_not_found_response(): void
    {
        Http::fake(['*' => Http::response(['error' => 'There is nothing here'], 404)]);

        try {
            $this->provider->fetchCharacters();
            $this->fail('A 404 should have been reported as CatalogUnavailable.');
        } catch (CatalogUnavailable) {
            // expected
        }

        Http::assertSentCount(1);
    }

    public function test_it_gives_up_after_the_configured_number_of_attempts(): void
    {
        Http::fake(['*' => Http::response('upstream exploded', 500)]);

        try {
            $this->provider->fetchCharacters();
            $this->fail('An exhausted retry budget should have been reported as CatalogUnavailable.');
        } catch (CatalogUnavailable) {
            // expected
        }

        Http::assertSentCount(3);
    }

    /**
     * The rate limiter answers with plain text rather than JSON. A body that is
     * not a JSON object has to be reported as such and never mistaken for an
     * empty page.
     */
    public function test_it_reports_a_body_that_is_not_json(): void
    {
        Http::fake(['*' => Http::response('error code: 1015', 200)]);

        $this->expectException(MalformedCatalogPayload::class);

        $this->provider->fetchCharacters();
    }

    public function test_it_reports_a_page_whose_next_points_at_another_host(): void
    {
        Http::fake(['*' => Http::response($this->page([], 'https://evil.example/api/character?page=2'))]);

        $this->expectException(MalformedCatalogPayload::class);

        $this->provider->fetchCharacters();
    }

    public function test_it_reports_a_page_whose_envelope_lost_the_next_key(): void
    {
        Http::fake(['*' => Http::response([
            'info' => ['count' => 826, 'pages' => 42],
            'results' => [['id' => 1, 'name' => 'Rick Sanchez']],
        ])]);

        $this->expectException(MalformedCatalogPayload::class);

        $this->provider->fetchCharacters();
    }
}
