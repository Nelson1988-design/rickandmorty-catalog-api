<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Catalog\Enums\CharacterGender;
use App\Domain\Catalog\Enums\CharacterStatus;
use App\Models\Character;
use App\Models\Episode;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function location(int $externalId, string $name): Location
    {
        return Location::create([
            'external_id' => $externalId,
            'name' => $name,
            'type' => 'Planet',
            'dimension' => 'Dimension C-137',
        ]);
    }

    private function episode(int $externalId, string $name): Episode
    {
        return Episode::create([
            'external_id' => $externalId,
            'name' => $name,
            'code' => 'S01E0'.$externalId,
            'air_date' => '2013-12-02',
            'air_date_raw' => 'December 2, 2013',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function character(int $externalId, string $name, array $attributes = []): Character
    {
        return Character::create(array_merge([
            'external_id' => $externalId,
            'name' => $name,
            'status' => CharacterStatus::Alive,
            'gender' => CharacterGender::Male,
            'species' => 'Human',
            'image' => 'https://rickandmortyapi.com/api/character/avatar/'.$externalId.'.jpeg',
        ], $attributes));
    }

    public function test_the_catalogue_is_readable_without_a_token(): void
    {
        $this->character(1, 'Rick Sanchez');

        $this->getJson('/api/v1/characters')->assertOk();
        $this->getJson('/api/v1/episodes')->assertOk();
        $this->getJson('/api/v1/locations')->assertOk();
    }

    public function test_the_character_list_is_paginated(): void
    {
        foreach (range(1, 25) as $externalId) {
            $this->character($externalId, 'Character '.$externalId);
        }

        $this->getJson('/api/v1/characters')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonPath('meta.total', 25);

        $this->getJson('/api/v1/characters?page=2')->assertJsonCount(5, 'data');
    }

    /**
     * A partial match, because somebody typing "rick" expects to find
     * "Toxic Rick" as well.
     */
    public function test_the_name_filter_matches_anywhere_in_the_name(): void
    {
        $this->character(1, 'Rick Sanchez');
        $this->character(2, 'Toxic Rick');
        $this->character(3, 'Morty Smith');

        $this->getJson('/api/v1/characters?name=rick')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_status_and_species_filters_match_exactly(): void
    {
        $this->character(1, 'Rick Sanchez', ['status' => CharacterStatus::Alive, 'species' => 'Human']);
        $this->character(2, 'Birdperson', ['status' => CharacterStatus::Dead, 'species' => 'Alien']);

        $this->getJson('/api/v1/characters?status=dead')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Birdperson');

        $this->getJson('/api/v1/characters?species=Alien')->assertJsonCount(1, 'data');
    }

    public function test_the_status_filter_does_not_care_about_capitalisation(): void
    {
        $this->character(1, 'Rick Sanchez', ['status' => CharacterStatus::Alive]);

        $this->getJson('/api/v1/characters?status=Alive')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/characters?status=ALIVE')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_filters_narrow_together(): void
    {
        $this->character(1, 'Rick Sanchez', ['status' => CharacterStatus::Alive, 'species' => 'Human']);
        $this->character(2, 'Toxic Rick', ['status' => CharacterStatus::Dead, 'species' => 'Human']);

        $this->getJson('/api/v1/characters?name=rick&status=dead')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Toxic Rick');
    }

    /**
     * A mistyped filter is answered, not ignored. Quietly returning the whole
     * catalogue to someone who asked for something else is worse than saying no.
     */
    public function test_an_unrecognised_status_is_refused(): void
    {
        $this->character(1, 'Rick Sanchez');

        $this->getJson('/api/v1/characters?status=undead')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('status');
    }

    public function test_a_character_detail_carries_its_relations(): void
    {
        $earth = $this->location(1, 'Earth (C-137)');
        $citadel = $this->location(3, 'Citadel of Ricks');
        $pilot = $this->episode(1, 'Pilot');

        $rick = $this->character(1, 'Rick Sanchez', [
            'origin_location_id' => $earth->id,
            'current_location_id' => $citadel->id,
        ]);
        $rick->episodes()->sync([$pilot->id]);

        $this->getJson("/api/v1/characters/{$rick->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Rick Sanchez')
            ->assertJsonPath('data.external_id', 1)
            ->assertJsonPath('data.origin.name', 'Earth (C-137)')
            ->assertJsonPath('data.location.name', 'Citadel of Ricks')
            ->assertJsonPath('data.episodes.0.code', 'S01E01');
    }

    /**
     * The relation the brief asks Location to expose, derived from the current
     * location and from nowhere else.
     */
    public function test_a_location_detail_lists_its_residents(): void
    {
        $earth = $this->location(1, 'Earth (C-137)');
        $citadel = $this->location(3, 'Citadel of Ricks');

        $this->character(1, 'Rick Sanchez', [
            'origin_location_id' => $earth->id,
            'current_location_id' => $citadel->id,
        ]);

        $this->getJson("/api/v1/locations/{$citadel->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.residents')
            ->assertJsonPath('data.residents.0.name', 'Rick Sanchez');

        // Originating somewhere does not make you a resident of it.
        $this->getJson("/api/v1/locations/{$earth->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.residents');
    }

    public function test_an_episode_detail_lists_its_characters(): void
    {
        $pilot = $this->episode(1, 'Pilot');
        $rick = $this->character(1, 'Rick Sanchez');
        $rick->episodes()->sync([$pilot->id]);

        $this->getJson("/api/v1/episodes/{$pilot->id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'S01E01')
            ->assertJsonPath('data.air_date', '2013-12-02')
            ->assertJsonPath('data.air_date_raw', 'December 2, 2013')
            ->assertJsonCount(1, 'data.characters');
    }

    public function test_a_record_that_does_not_exist_answers_not_found(): void
    {
        $this->getJson('/api/v1/characters/4242')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    /**
     * A list must never fire a query per row while it is being rendered.
     */
    public function test_listing_characters_does_not_grow_queries_with_rows(): void
    {
        $earth = $this->location(1, 'Earth (C-137)');

        foreach (range(1, 10) as $externalId) {
            $this->character($externalId, 'Character '.$externalId, [
                'origin_location_id' => $earth->id,
                'current_location_id' => $earth->id,
            ]);
        }

        DB::enableQueryLog();
        $this->getJson('/api/v1/characters')->assertOk()->assertJsonCount(10, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $queries, "Rendering ten rows took {$queries} queries.");
    }
}
