<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\RickAndMorty\Mappers;

use App\Domain\Catalog\Enums\CharacterGender;
use App\Domain\Catalog\Enums\CharacterStatus;
use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;
use App\Infrastructure\RickAndMorty\Mappers\CharacterMapper;
use PHPUnit\Framework\TestCase;

/**
 * No network, no configuration, no framework.
 */
final class CharacterMapperTest extends TestCase
{
    private const API = 'https://rickandmortyapi.com/api';

    private CharacterMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new CharacterMapper;
    }

    public function test_it_maps_a_complete_record(): void
    {
        $character = $this->mapper->map([
            'id' => 1,
            'name' => 'Rick Sanchez',
            'status' => 'Alive',
            'species' => 'Human',
            'type' => '',
            'gender' => 'Male',
            'image' => self::API.'/character/avatar/1.jpeg',
            'origin' => ['name' => 'Earth (C-137)', 'url' => self::API.'/location/1'],
            'location' => ['name' => 'Citadel of Ricks', 'url' => self::API.'/location/3'],
            'episode' => [self::API.'/episode/1', self::API.'/episode/2'],
        ]);

        $this->assertSame(1, $character->externalId);
        $this->assertSame('Rick Sanchez', $character->name);
        $this->assertSame(CharacterStatus::Alive, $character->status);
        $this->assertSame('Human', $character->species);
        $this->assertSame(CharacterGender::Male, $character->gender);
        $this->assertSame(1, $character->originLocationExternalId);
        $this->assertSame(3, $character->currentLocationExternalId);
        $this->assertSame([1, 2], $character->episodeExternalIds);
    }

    /**
     * Half the catalogue carries an empty type.
     */
    public function test_it_turns_an_empty_type_into_null(): void
    {
        $character = $this->mapper->map([
            'id' => 1,
            'name' => 'Rick Sanchez',
            'type' => '',
        ]);

        $this->assertNull($character->type);
    }

    public function test_it_turns_an_unknown_species_into_null(): void
    {
        $character = $this->mapper->map([
            'id' => 2,
            'name' => 'Morty Smith',
            'species' => 'unknown',
        ]);

        $this->assertNull($character->species);
    }

    /**
     * The same word that means absence in free text is a real answer here: 100
     * characters have no established status and 49 no established gender.
     */
    public function test_it_maps_an_unknown_status_to_the_unknown_case_not_to_null(): void
    {
        $character = $this->mapper->map([
            'id' => 8,
            'name' => 'Adjudicator Rick',
            'status' => 'unknown',
            'gender' => 'unknown',
        ]);

        $this->assertSame(CharacterStatus::Unknown, $character->status);
        $this->assertSame(CharacterGender::Unknown, $character->gender);
    }

    public function test_it_maps_an_absent_status_to_the_unknown_case(): void
    {
        $character = $this->mapper->map(['id' => 8, 'name' => 'Adjudicator Rick']);

        $this->assertSame(CharacterStatus::Unknown, $character->status);
        $this->assertSame(CharacterGender::Unknown, $character->gender);
    }

    /**
     * A fourth status appearing tomorrow must not break a whole run.
     */
    public function test_it_degrades_an_unrecognised_status_to_unknown(): void
    {
        $character = $this->mapper->map([
            'id' => 9,
            'name' => 'Zombie Morty',
            'status' => 'Undead',
        ]);

        $this->assertSame(CharacterStatus::Unknown, $character->status);
    }

    public function test_it_reads_the_status_regardless_of_its_capitalisation(): void
    {
        $character = $this->mapper->map([
            'id' => 3,
            'name' => 'Summer Smith',
            'status' => 'DEAD',
            'gender' => 'FEMALE',
        ]);

        $this->assertSame(CharacterStatus::Dead, $character->status);
        $this->assertSame(CharacterGender::Female, $character->gender);
    }

    /**
     * 300 characters have no origin to point at, and 21 no current location.
     */
    public function test_it_returns_null_when_a_location_reference_is_empty(): void
    {
        $character = $this->mapper->map([
            'id' => 8,
            'name' => 'Adjudicator Rick',
            'origin' => ['name' => 'unknown', 'url' => ''],
            'location' => ['name' => 'unknown', 'url' => ''],
        ]);

        $this->assertNull($character->originLocationExternalId);
        $this->assertNull($character->currentLocationExternalId);
    }

    public function test_it_returns_null_when_the_location_key_is_missing_altogether(): void
    {
        $character = $this->mapper->map(['id' => 8, 'name' => 'Adjudicator Rick']);

        $this->assertNull($character->originLocationExternalId);
        $this->assertNull($character->currentLocationExternalId);
        $this->assertSame([], $character->episodeExternalIds);
    }

    public function test_it_removes_duplicate_episode_references(): void
    {
        $character = $this->mapper->map([
            'id' => 1,
            'name' => 'Rick Sanchez',
            'episode' => [self::API.'/episode/1', self::API.'/episode/2', self::API.'/episode/1'],
        ]);

        $this->assertSame([1, 2], $character->episodeExternalIds);
    }

    /**
     * The check that matters most in this mapper. Without it a reference to a
     * character sitting in an origin field would silently become a location id,
     * and the resulting row would look perfectly valid.
     */
    public function test_it_rejects_a_reference_that_points_at_the_wrong_resource(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('points at a character');

        $this->mapper->map([
            'id' => 1,
            'name' => 'Rick Sanchez',
            'origin' => ['name' => 'Earth', 'url' => self::API.'/character/5'],
        ]);
    }

    public function test_it_rejects_a_reference_it_cannot_read_an_identifier_from(): void
    {
        $this->expectException(MalformedCatalogPayload::class);

        $this->mapper->map([
            'id' => 1,
            'name' => 'Rick Sanchez',
            'location' => ['name' => 'Somewhere', 'url' => 'https://rickandmortyapi.com/api/location/'],
        ]);
    }

    public function test_it_rejects_an_episode_list_that_is_not_a_list(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('episode');

        $this->mapper->map([
            'id' => 1,
            'name' => 'Rick Sanchez',
            'episode' => 'https://rickandmortyapi.com/api/episode/1',
        ]);
    }

    public function test_it_rejects_a_blank_entry_inside_the_episode_list(): void
    {
        $this->expectException(MalformedCatalogPayload::class);

        $this->mapper->map([
            'id' => 1,
            'name' => 'Rick Sanchez',
            'episode' => [self::API.'/episode/1', ''],
        ]);
    }

    public function test_it_rejects_a_record_without_an_identifier(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('id');

        $this->mapper->map(['name' => 'Rick Sanchez']);
    }

    public function test_it_rejects_a_record_without_a_name(): void
    {
        $this->expectException(MalformedCatalogPayload::class);
        $this->expectExceptionMessage('name');

        $this->mapper->map(['id' => 1, 'status' => 'Alive']);
    }
}
