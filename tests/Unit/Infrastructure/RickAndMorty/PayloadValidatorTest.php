<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\RickAndMorty;

use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;
use App\Infrastructure\RickAndMorty\PayloadValidator;
use Tests\TestCase;

/**
 * The validator reads the configured host through `config()`, so these tests
 * boot the framework. No network is involved: every payload is handed in
 * directly, which is the whole point of keeping validation separate from the
 * HTTP client.
 */
final class PayloadValidatorTest extends TestCase
{
    private const API_URL = 'https://rickandmortyapi.com/api';

    private PayloadValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rickandmorty.api_url', self::API_URL);

        $this->validator = new PayloadValidator;
    }

    public function test_it_returns_the_normalised_envelope_of_a_valid_page(): void
    {
        $page = $this->validator->validatePage('character', [
            'info' => [
                'count' => 826,
                'pages' => 42,
                'next' => self::API_URL.'/character?page=3',
                'prev' => self::API_URL.'/character?page=1',
            ],
            'results' => [
                ['id' => 21, 'name' => 'Aqua Morty'],
                ['id' => 22, 'name' => 'Aqua Rick'],
            ],
        ]);

        $this->assertSame(self::API_URL.'/character?page=3', $page['next']);
        $this->assertSame(826, $page['count']);
        $this->assertCount(2, $page['results']);
        $this->assertSame('Aqua Morty', $page['results'][0]['name']);
    }

    public function test_it_treats_a_null_next_as_the_last_page(): void
    {
        $page = $this->validator->validatePage('character', [
            'info' => ['count' => 826, 'next' => null],
            'results' => [['id' => 826, 'name' => 'Butter Robot']],
        ]);

        $this->assertNull($page['next']);
    }

    public function test_it_accepts_an_empty_results_list(): void
    {
        $page = $this->validator->validatePage('location', [
            'info' => ['count' => 0, 'next' => null],
            'results' => [],
        ]);

        $this->assertSame([], $page['results']);
        $this->assertSame(0, $page['count']);
    }

    public function test_it_defaults_the_count_to_zero_when_it_is_absent(): void
    {
        $page = $this->validator->validatePage('episode', [
            'info' => ['next' => null],
            'results' => [['id' => 1, 'name' => 'Pilot']],
        ]);

        $this->assertSame(0, $page['count']);
    }

    public function test_it_rejects_a_body_that_is_not_an_object(): void
    {
        $this->expectException(MalformedCatalogPayload::class);

        $this->validator->validatePage('character', 'There is nothing here');
    }

    public function test_it_rejects_a_page_without_results(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('results');

        $this->validator->validatePage('character', [
            'info' => ['count' => 0, 'next' => null],
        ]);
    }

    public function test_it_rejects_results_that_is_not_a_list(): void
    {
        $this->expectException(MalformedCatalogPayload::class);

        $this->validator->validatePage('character', [
            'info' => ['count' => 0, 'next' => null],
            'results' => 'nope',
        ]);
    }

    public function test_it_rejects_a_result_entry_that_is_not_an_object(): void
    {
        $this->expectException(MalformedCatalogPayload::class);

        $this->validator->validatePage('character', [
            'info' => ['count' => 1, 'next' => null],
            'results' => [['id' => 1, 'name' => 'Rick Sanchez'], 'not an object'],
        ]);
    }

    public function test_it_rejects_a_page_without_info(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('info');

        $this->validator->validatePage('character', [
            'results' => [['id' => 1, 'name' => 'Rick Sanchez']],
        ]);
    }

    /**
     * The one that matters most. An absent `next` key is not the end of the
     * collection: it means the envelope changed shape and we no longer know
     * whether more pages exist. Reading it as "we are done" would truncate the
     * catalogue while still reporting success.
     */
    public function test_it_rejects_info_without_a_next_key(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('info.next');

        $this->validator->validatePage('character', [
            'info' => ['count' => 826, 'pages' => 42],
            'results' => [['id' => 1, 'name' => 'Rick Sanchez']],
        ]);
    }

    public function test_it_rejects_a_next_that_is_neither_a_string_nor_null(): void
    {
        $this->expectException(MalformedCatalogPayload::class);

        $this->validator->validatePage('character', [
            'info' => ['count' => 826, 'next' => 3],
            'results' => [['id' => 1, 'name' => 'Rick Sanchez']],
        ]);
    }

    /**
     * `next` is a URL chosen by a third party and followed by our HTTP client.
     * It must still point at the host we configured.
     */
    public function test_it_rejects_a_next_pointing_at_another_host(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('outside the configured catalog host');

        $this->validator->validatePage('character', [
            'info' => ['count' => 826, 'next' => 'https://evil.example/api/character?page=3'],
            'results' => [['id' => 1, 'name' => 'Rick Sanchez']],
        ]);
    }
}
