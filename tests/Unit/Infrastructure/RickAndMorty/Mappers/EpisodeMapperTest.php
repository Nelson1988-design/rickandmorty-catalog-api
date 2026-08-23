<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\RickAndMorty\Mappers;

use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;
use App\Infrastructure\RickAndMorty\Mappers\EpisodeMapper;
use PHPUnit\Framework\TestCase;

/**
 * No network, no configuration, no framework.
 */
final class EpisodeMapperTest extends TestCase
{
    private EpisodeMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new EpisodeMapper;
    }

    public function test_it_maps_a_complete_record(): void
    {
        $episode = $this->mapper->map([
            'id' => 1,
            'name' => 'Pilot',
            'air_date' => 'December 2, 2013',
            'episode' => 'S01E01',
            'characters' => ['https://rickandmortyapi.com/api/character/1'],
        ]);

        $this->assertSame(1, $episode->externalId);
        $this->assertSame('Pilot', $episode->name);
        $this->assertSame('S01E01', $episode->code);
        $this->assertSame('2013-12-02', $episode->airDate?->format('Y-m-d'));
        $this->assertSame('December 2, 2013', $episode->airDateRaw);
    }

    /**
     * The parsed date must land on midnight. If the time component were left to
     * the clock, the same record would map to a different value on every run
     * and the synchronisation could never be idempotent.
     */
    public function test_it_parses_the_date_to_midnight(): void
    {
        $episode = $this->mapper->map([
            'id' => 1,
            'name' => 'Pilot',
            'air_date' => 'December 2, 2013',
        ]);

        $this->assertSame('2013-12-02 00:00:00', $episode->airDate?->format('Y-m-d H:i:s'));
    }

    public function test_it_maps_the_same_record_to_the_same_value_every_time(): void
    {
        $record = ['id' => 1, 'name' => 'Pilot', 'air_date' => 'December 2, 2013'];

        $first = $this->mapper->map($record);
        $second = $this->mapper->map($record);

        $this->assertEquals($first->airDate, $second->airDate);
    }

    public function test_it_keeps_the_original_string_when_the_format_is_unexpected(): void
    {
        $episode = $this->mapper->map([
            'id' => 2,
            'name' => 'Lawnmower Dog',
            'air_date' => '2013-12-09',
        ]);

        $this->assertNull($episode->airDate);
        $this->assertSame('2013-12-09', $episode->airDateRaw);
    }

    /**
     * PHP happily rolls an impossible date over into the next month and only
     * reports it as a warning. Storing a date the provider never sent would be
     * worse than storing none.
     */
    public function test_it_refuses_a_date_that_overflows_into_the_next_month(): void
    {
        $episode = $this->mapper->map([
            'id' => 3,
            'name' => 'Anatomy Park',
            'air_date' => 'February 30, 2013',
        ]);

        $this->assertNull($episode->airDate);
        $this->assertSame('February 30, 2013', $episode->airDateRaw);
    }

    public function test_it_treats_an_absent_air_date_as_null_on_both_fields(): void
    {
        $episode = $this->mapper->map([
            'id' => 4,
            'name' => 'M. Night Shaym-Aliens!',
        ]);

        $this->assertNull($episode->airDate);
        $this->assertNull($episode->airDateRaw);
    }

    public function test_it_treats_an_empty_air_date_as_null_on_both_fields(): void
    {
        $episode = $this->mapper->map([
            'id' => 5,
            'name' => 'Meeseeks and Destroy',
            'air_date' => '   ',
        ]);

        $this->assertNull($episode->airDate);
        $this->assertNull($episode->airDateRaw);
    }

    /**
     * The provider sends "S01E01" today, but rejecting anything else would
     * discard a special or a renumbered episode rather than store it.
     */
    public function test_it_does_not_enforce_the_shape_of_the_episode_code(): void
    {
        $episode = $this->mapper->map([
            'id' => 6,
            'name' => 'Interdimensional Cable Special',
            'episode' => 'S02E04B',
        ]);

        $this->assertSame('S02E04B', $episode->code);
    }

    public function test_it_treats_an_empty_code_as_null(): void
    {
        $episode = $this->mapper->map([
            'id' => 7,
            'name' => 'Raising Gazorpazorp',
            'episode' => '',
        ]);

        $this->assertNull($episode->code);
    }

    public function test_it_rejects_a_record_without_an_identifier(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('id');

        $this->mapper->map(['name' => 'Pilot']);
    }

    public function test_it_rejects_a_record_without_a_name(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('name');

        $this->mapper->map(['id' => 1, 'air_date' => 'December 2, 2013']);
    }
}
