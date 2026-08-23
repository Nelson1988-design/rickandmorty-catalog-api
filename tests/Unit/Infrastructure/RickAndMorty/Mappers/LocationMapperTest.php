<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\RickAndMorty\Mappers;

use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;
use App\Infrastructure\RickAndMorty\Mappers\LocationMapper;
use PHPUnit\Framework\TestCase;

/**
 * The mapper touches neither the network nor the configuration, so this test
 * runs without booting the framework.
 */
final class LocationMapperTest extends TestCase
{
    private LocationMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new LocationMapper;
    }

    public function test_it_maps_a_complete_record(): void
    {
        $location = $this->mapper->map([
            'id' => 1,
            'name' => 'Earth (C-137)',
            'type' => 'Planet',
            'dimension' => 'Dimension C-137',
            'residents' => ['https://rickandmortyapi.com/api/character/38'],
        ]);

        $this->assertSame(1, $location->externalId);
        $this->assertSame('Earth (C-137)', $location->name);
        $this->assertSame('Planet', $location->type);
        $this->assertSame('Dimension C-137', $location->dimension);
    }

    public function test_it_turns_an_empty_string_into_null(): void
    {
        $location = $this->mapper->map([
            'id' => 3,
            'name' => 'Citadel of Ricks',
            'type' => '',
            'dimension' => '',
        ]);

        $this->assertNull($location->type);
        $this->assertNull($location->dimension);
    }

    public function test_it_treats_the_exact_word_unknown_as_absence(): void
    {
        $location = $this->mapper->map([
            'id' => 6,
            'name' => 'Abadango',
            'type' => 'Cluster',
            'dimension' => 'unknown',
        ]);

        $this->assertNull($location->dimension);
    }

    /**
     * Two real locations are named "Unknown dimension". A substring rule would
     * discard a legitimate value, so the sentinel is matched whole.
     */
    public function test_it_keeps_a_value_that_merely_contains_the_word_unknown(): void
    {
        $location = $this->mapper->map([
            'id' => 24,
            'name' => 'Fantasy World',
            'type' => 'Planet',
            'dimension' => 'Unknown dimension',
        ]);

        $this->assertSame('Unknown dimension', $location->dimension);
    }

    public function test_it_treats_an_absent_optional_field_as_null(): void
    {
        $location = $this->mapper->map([
            'id' => 9,
            'name' => 'Purge Planet',
        ]);

        $this->assertNull($location->type);
        $this->assertNull($location->dimension);
    }

    public function test_it_trims_surrounding_whitespace(): void
    {
        $location = $this->mapper->map([
            'id' => 10,
            'name' => '  Venzenulon 7  ',
            'type' => '  Planet  ',
            'dimension' => null,
        ]);

        $this->assertSame('Venzenulon 7', $location->name);
        $this->assertSame('Planet', $location->type);
        $this->assertNull($location->dimension);
    }

    public function test_it_accepts_an_identifier_sent_as_a_numeric_string(): void
    {
        $location = $this->mapper->map([
            'id' => '42',
            'name' => 'Anatomy Park',
        ]);

        $this->assertSame(42, $location->externalId);
    }

    public function test_it_rejects_a_record_without_an_identifier(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('id');

        $this->mapper->map(['name' => 'Nameless place']);
    }

    public function test_it_rejects_an_identifier_that_is_not_a_whole_number(): void
    {
        $this->expectException(MalformedCatalogPayload::class);

        $this->mapper->map(['id' => 'abc', 'name' => 'Anatomy Park']);
    }

    public function test_it_rejects_a_record_without_a_name(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('name');

        $this->mapper->map(['id' => 7]);
    }

    public function test_it_rejects_a_blank_name(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('name');

        $this->mapper->map(['id' => 7, 'name' => '   ']);
    }
}
