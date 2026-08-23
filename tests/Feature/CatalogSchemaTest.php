<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Enums\CharacterGender;
use App\Domain\Catalog\Enums\CharacterStatus;
use App\Models\Character;
use App\Models\Episode;
use App\Models\Location;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Exercises the schema against real MySQL: the relationships, the constraints
 * that are supposed to hold, and the ones that are supposed not to.
 */
final class CatalogSchemaTest extends TestCase
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

    private function episode(int $externalId, string $name, ?string $code = null): Episode
    {
        return Episode::create([
            'external_id' => $externalId,
            'name' => $name,
            'code' => $code,
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
            'type' => null,
            'image' => 'https://rickandmortyapi.com/api/character/avatar/1.jpeg',
        ], $attributes));
    }

    public function test_a_character_points_at_two_different_locations(): void
    {
        $earth = $this->location(1, 'Earth (C-137)');
        $citadel = $this->location(3, 'Citadel of Ricks');

        $rick = $this->character(1, 'Rick Sanchez', [
            'origin_location_id' => $earth->id,
            'current_location_id' => $citadel->id,
        ]);

        $this->assertTrue($rick->origin->is($earth));
        $this->assertTrue($rick->currentLocation->is($citadel));
    }

    /**
     * 300 of the 826 characters have no origin and 21 no current location.
     */
    public function test_both_location_references_accept_null(): void
    {
        $adjudicator = $this->character(8, 'Adjudicator Rick', [
            'origin_location_id' => null,
            'current_location_id' => null,
        ]);

        $this->assertNull($adjudicator->fresh()->origin);
        $this->assertNull($adjudicator->fresh()->currentLocation);
    }

    /**
     * Residency comes from the current location alone. A character that
     * originated somewhere but lives elsewhere is not a resident of its origin.
     */
    public function test_residents_are_derived_from_the_current_location_only(): void
    {
        $earth = $this->location(1, 'Earth (C-137)');
        $citadel = $this->location(3, 'Citadel of Ricks');

        $this->character(1, 'Rick Sanchez', [
            'origin_location_id' => $earth->id,
            'current_location_id' => $citadel->id,
        ]);

        $this->assertCount(0, $earth->residents);
        $this->assertCount(1, $citadel->residents);
        $this->assertSame('Rick Sanchez', $citadel->residents->first()->name);
    }

    /**
     * 32 locations have nobody living in them. The relationship has to answer
     * with an empty collection rather than break.
     */
    public function test_a_location_with_no_residents_answers_with_an_empty_collection(): void
    {
        $empty = $this->location(99, 'Nowhere in particular');

        $this->assertCount(0, $empty->residents);
        $this->assertTrue($empty->residents->isEmpty());
    }

    public function test_the_many_to_many_reads_from_both_sides(): void
    {
        $rick = $this->character(1, 'Rick Sanchez');
        $pilot = $this->episode(1, 'Pilot', 'S01E01');
        $dog = $this->episode(2, 'Lawnmower Dog', 'S01E02');

        $rick->episodes()->sync([$pilot->id, $dog->id]);

        $this->assertCount(2, $rick->fresh()->episodes);
        $this->assertCount(1, $pilot->fresh()->characters);
    }

    /**
     * Running the same sync twice must leave the relationship untouched. This
     * is the idempotency the synchronisation command will rely on.
     */
    public function test_syncing_the_same_episodes_twice_changes_nothing(): void
    {
        $rick = $this->character(1, 'Rick Sanchez');
        $pilot = $this->episode(1, 'Pilot', 'S01E01');

        $rick->episodes()->sync([$pilot->id]);
        $rick->episodes()->sync([$pilot->id]);

        $this->assertDatabaseCount('character_episode', 1);
    }

    public function test_the_pivot_refuses_a_duplicated_pair(): void
    {
        $rick = $this->character(1, 'Rick Sanchez');
        $pilot = $this->episode(1, 'Pilot', 'S01E01');

        $rick->episodes()->attach($pilot->id);

        $this->expectException(QueryException::class);

        $rick->episodes()->attach($pilot->id);
    }

    /**
     * The loud failure the design depends on: a pivot row can never point at an
     * episode that was not synchronised.
     */
    public function test_the_pivot_refuses_an_episode_that_does_not_exist(): void
    {
        $rick = $this->character(1, 'Rick Sanchez');

        $this->expectException(QueryException::class);

        $rick->episodes()->attach(4242);
    }

    public function test_the_external_identifier_is_unique(): void
    {
        $this->character(1, 'Rick Sanchez');

        $this->expectException(QueryException::class);

        $this->character(1, 'Another Rick entirely');
    }

    /**
     * Deliberately not unique: the code belongs to the provider, and a two-part
     * special sharing one would be a legitimate change, not a corruption.
     */
    public function test_two_episodes_may_share_a_code(): void
    {
        $this->episode(1, 'Special, part one', 'S07E01');
        $this->episode(2, 'Special, part two', 'S07E01');

        $this->assertDatabaseCount('episodes', 2);
    }

    public function test_the_enums_survive_a_round_trip(): void
    {
        $this->character(8, 'Adjudicator Rick', [
            'status' => CharacterStatus::Unknown,
            'gender' => CharacterGender::Genderless,
        ]);

        $stored = Character::where('external_id', 8)->firstOrFail();

        $this->assertSame(CharacterStatus::Unknown, $stored->status);
        $this->assertSame(CharacterGender::Genderless, $stored->gender);
        $this->assertDatabaseHas('characters', ['external_id' => 8, 'status' => 'unknown']);
    }

    public function test_the_air_date_is_stored_as_a_date_and_the_raw_string_survives(): void
    {
        $pilot = $this->episode(1, 'Pilot', 'S01E01')->fresh();

        $this->assertSame('2013-12-02', $pilot->air_date->format('Y-m-d'));
        $this->assertSame('December 2, 2013', $pilot->air_date_raw);
    }

    public function test_the_catalog_tables_carry_no_timestamps(): void
    {
        foreach (['characters', 'episodes', 'locations'] as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'updated_at'),
                "The {$table} table should not carry timestamps.",
            );
        }
    }
}
