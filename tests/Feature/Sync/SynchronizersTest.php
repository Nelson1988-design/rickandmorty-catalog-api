<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Application\Sync\Synchronizers\CharacterSynchronizer;
use App\Application\Sync\Synchronizers\EpisodeSynchronizer;
use App\Application\Sync\Synchronizers\LocationSynchronizer;
use App\Domain\Catalog\Data\CharacterData;
use App\Domain\Catalog\Data\EpisodeData;
use App\Domain\Catalog\Data\LocationData;
use App\Domain\Catalog\Enums\CharacterGender;
use App\Domain\Catalog\Enums\CharacterStatus;
use App\Domain\Catalog\Exceptions\CatalogUnavailable;
use App\Domain\Sync\Exceptions\IncompleteCatalog;
use App\Models\Character;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeCatalog;
use Tests\TestCase;

/**
 * No network anywhere: the port is filled with a double, which is exactly what
 * the interface was introduced for.
 */
final class SynchronizersTest extends TestCase
{
    use RefreshDatabase;

    private function locationData(int $id, string $name): LocationData
    {
        return new LocationData($id, $name, 'Planet', 'Dimension C-137');
    }

    private function episodeData(int $id, string $name): EpisodeData
    {
        return new EpisodeData($id, $name, 'S01E0'.$id, new DateTimeImmutable('2013-12-02 00:00:00'), 'December 2, 2013');
    }

    /**
     * @param  list<int>  $episodes
     */
    private function characterData(int $id, string $name, ?int $origin = null, ?int $current = null, array $episodes = []): CharacterData
    {
        return new CharacterData(
            externalId: $id,
            name: $name,
            status: CharacterStatus::Alive,
            species: 'Human',
            type: null,
            gender: CharacterGender::Male,
            image: 'https://rickandmortyapi.com/api/character/avatar/'.$id.'.jpeg',
            originLocationExternalId: $origin,
            currentLocationExternalId: $current,
            episodeExternalIds: $episodes,
        );
    }

    public function test_it_walks_every_page_following_the_cursor(): void
    {
        $catalog = (new FakeCatalog)->serve('location', [
            [$this->locationData(1, 'Earth (C-137)'), $this->locationData(2, 'Abadango')],
            [$this->locationData(3, 'Citadel of Ricks')],
        ]);

        $report = (new LocationSynchronizer($catalog))->synchronize();

        $this->assertSame(2, $report->pages);
        $this->assertSame(3, $report->records);
        $this->assertTrue($report->completed());
        $this->assertDatabaseCount('locations', 3);
    }

    /**
     * The requirement stated as an assertion: running twice must leave the
     * database exactly as the first run left it.
     */
    public function test_running_twice_leaves_the_database_untouched(): void
    {
        $pages = [[$this->locationData(1, 'Earth (C-137)'), $this->locationData(2, 'Abadango')]];

        (new LocationSynchronizer((new FakeCatalog)->serve('location', $pages)))->synchronize();
        $afterFirst = DB::table('locations')->orderBy('id')->get()->toJson();

        (new LocationSynchronizer((new FakeCatalog)->serve('location', $pages)))->synchronize();
        $afterSecond = DB::table('locations')->orderBy('id')->get()->toJson();

        $this->assertSame($afterFirst, $afterSecond);
        $this->assertDatabaseCount('locations', 2);
    }

    public function test_a_changed_record_is_updated_rather_than_duplicated(): void
    {
        (new LocationSynchronizer((new FakeCatalog)->serve('location', [
            [$this->locationData(1, 'Earth (C-137)')],
        ])))->synchronize();

        (new LocationSynchronizer((new FakeCatalog)->serve('location', [
            [new LocationData(1, 'Earth (Replacement Dimension)', 'Planet', 'Replacement Dimension')],
        ])))->synchronize();

        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseHas('locations', [
            'external_id' => 1,
            'name' => 'Earth (Replacement Dimension)',
            'dimension' => 'Replacement Dimension',
        ]);
    }

    /**
     * A page that cannot be downloaded ends the resource. What was already
     * committed stays, and the report carries both the count and the reason.
     */
    public function test_a_failing_page_stops_the_resource_and_keeps_what_was_written(): void
    {
        $catalog = (new FakeCatalog)->serve('location', [
            [$this->locationData(1, 'Earth (C-137)'), $this->locationData(2, 'Abadango')],
            CatalogUnavailable::afterRetries('location', 3),
            [$this->locationData(3, 'Never reached')],
        ]);

        $report = (new LocationSynchronizer($catalog))->synchronize();

        $this->assertFalse($report->completed());
        $this->assertSame(1, $report->pages);
        $this->assertSame(2, $report->records);
        $this->assertStringContainsString('after 3 attempts', $report->stoppedBecause);
        $this->assertDatabaseCount('locations', 2);
    }

    public function test_it_stores_the_air_date_as_a_day_and_keeps_the_original_string(): void
    {
        (new EpisodeSynchronizer((new FakeCatalog)->serve('episode', [
            [$this->episodeData(1, 'Pilot')],
        ])))->synchronize();

        $this->assertDatabaseHas('episodes', [
            'external_id' => 1,
            'air_date' => '2013-12-02',
            'air_date_raw' => 'December 2, 2013',
        ]);
    }

    public function test_it_translates_external_references_into_local_keys(): void
    {
        $catalog = (new FakeCatalog)
            ->serve('location', [[$this->locationData(1, 'Earth (C-137)'), $this->locationData(3, 'Citadel of Ricks')]])
            ->serve('episode', [[$this->episodeData(1, 'Pilot'), $this->episodeData(2, 'Lawnmower Dog')]])
            ->serve('character', [[$this->characterData(1, 'Rick Sanchez', origin: 1, current: 3, episodes: [1, 2])]]);

        (new LocationSynchronizer($catalog))->synchronize();
        (new EpisodeSynchronizer($catalog))->synchronize();
        (new CharacterSynchronizer($catalog))->synchronize();

        $rick = Character::where('external_id', 1)->firstOrFail();

        $this->assertSame('Earth (C-137)', $rick->origin->name);
        $this->assertSame('Citadel of Ricks', $rick->currentLocation->name);
        $this->assertCount(2, $rick->episodes);
        $this->assertNotSame(1, $rick->origin->id, 'The local key must not be the provider identifier.');
    }

    public function test_a_character_with_no_place_to_point_at_keeps_both_references_null(): void
    {
        $catalog = (new FakeCatalog)
            ->serve('character', [[$this->characterData(8, 'Adjudicator Rick')]]);

        (new CharacterSynchronizer($catalog))->synchronize();

        $adjudicator = Character::where('external_id', 8)->firstOrFail();

        $this->assertNull($adjudicator->origin_location_id);
        $this->assertNull($adjudicator->current_location_id);
        $this->assertCount(0, $adjudicator->episodes);
    }

    public function test_running_the_character_pass_twice_leaves_the_pivot_untouched(): void
    {
        $build = fn (): FakeCatalog => (new FakeCatalog)
            ->serve('episode', [[$this->episodeData(1, 'Pilot'), $this->episodeData(2, 'Lawnmower Dog')]])
            ->serve('character', [[$this->characterData(1, 'Rick Sanchez', episodes: [1, 2])]]);

        $first = $build();
        (new EpisodeSynchronizer($first))->synchronize();
        (new CharacterSynchronizer($first))->synchronize();

        (new CharacterSynchronizer($build()))->synchronize();

        $this->assertDatabaseCount('character_episode', 2);
        $this->assertDatabaseCount('characters', 1);
    }

    /**
     * The loud failure. A reference to something that was never synchronised
     * cannot be dropped quietly: it means the pass before did not finish.
     */
    public function test_a_reference_to_an_unsynchronised_episode_aborts(): void
    {
        $catalog = (new FakeCatalog)
            ->serve('character', [[$this->characterData(1, 'Rick Sanchez', episodes: [42])]]);

        $this->expectException(IncompleteCatalog::class);
        $this->expectExceptionMessage('episode 42');

        (new CharacterSynchronizer($catalog))->synchronize();
    }

    public function test_a_reference_to_an_unsynchronised_location_aborts(): void
    {
        $catalog = (new FakeCatalog)
            ->serve('character', [[$this->characterData(1, 'Rick Sanchez', origin: 99)]]);

        $this->expectException(IncompleteCatalog::class);
        $this->expectExceptionMessage('location 99');

        (new CharacterSynchronizer($catalog))->synchronize();
    }

    /**
     * The transaction boundary, observed from the outside: a page either lands
     * whole or not at all.
     */
    public function test_a_page_that_fails_midway_leaves_nothing_of_itself_behind(): void
    {
        $catalog = (new FakeCatalog)
            ->serve('character', [[
                $this->characterData(1, 'Rick Sanchez'),
                $this->characterData(2, 'Morty Smith', episodes: [404]),
            ]]);

        try {
            (new CharacterSynchronizer($catalog))->synchronize();
            $this->fail('An unsynchronised reference should have aborted the run.');
        } catch (IncompleteCatalog) {
            // expected
        }

        $this->assertDatabaseCount('characters', 0);
    }
}
