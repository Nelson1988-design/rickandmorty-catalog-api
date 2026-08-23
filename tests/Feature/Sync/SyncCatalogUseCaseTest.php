<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Application\Sync\SyncCatalogUseCase;
use App\Domain\Catalog\Contracts\CatalogProvider;
use App\Domain\Catalog\Data\CharacterData;
use App\Domain\Catalog\Data\EpisodeData;
use App\Domain\Catalog\Data\LocationData;
use App\Domain\Catalog\Enums\CharacterGender;
use App\Domain\Catalog\Enums\CharacterStatus;
use App\Domain\Catalog\Exceptions\CatalogUnavailable;
use App\Domain\Sync\Enums\SyncRunStatus;
use App\Domain\Sync\Exceptions\IncompleteCatalog;
use App\Models\SyncRun;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeCatalog;
use Tests\TestCase;

final class SyncCatalogUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): FakeCatalog
    {
        return (new FakeCatalog)
            ->serve('location', [[
                new LocationData(1, 'Earth (C-137)', 'Planet', 'Dimension C-137'),
                new LocationData(3, 'Citadel of Ricks', 'Space station', null),
            ]])
            ->serve('episode', [[
                new EpisodeData(1, 'Pilot', 'S01E01', new DateTimeImmutable('2013-12-02'), 'December 2, 2013'),
            ]])
            ->serve('character', [[
                new CharacterData(
                    externalId: 1,
                    name: 'Rick Sanchez',
                    status: CharacterStatus::Alive,
                    species: 'Human',
                    type: null,
                    gender: CharacterGender::Male,
                    image: null,
                    originLocationExternalId: 1,
                    currentLocationExternalId: 3,
                    episodeExternalIds: [1],
                ),
            ]]);
    }

    private function useCaseFor(CatalogProvider $catalog): SyncCatalogUseCase
    {
        $this->app->instance(CatalogProvider::class, $catalog);

        return $this->app->make(SyncCatalogUseCase::class);
    }

    public function test_it_runs_the_three_resources_in_the_only_order_they_can_run_in(): void
    {
        $catalog = $this->catalog();

        $this->useCaseFor($catalog)->execute();

        $this->assertSame(['location', 'episode', 'character'], $catalog->order);
    }

    public function test_a_complete_run_is_recorded_with_what_each_resource_wrote(): void
    {
        $run = $this->useCaseFor($this->catalog())->execute();

        $this->assertSame(SyncRunStatus::Completed, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error);

        $this->assertSame(2, $run->stats['location']['records']);
        $this->assertSame(1, $run->stats['episode']['records']);
        $this->assertSame(1, $run->stats['character']['records']);

        $this->assertDatabaseCount('characters', 1);
        $this->assertDatabaseCount('character_episode', 1);
    }

    /**
     * The requirement of the brief, stated end to end: two full runs must leave
     * the database in exactly the same state.
     */
    public function test_two_full_runs_leave_the_database_identical(): void
    {
        $this->useCaseFor($this->catalog())->execute();

        $snapshot = fn (): string => collect(['locations', 'episodes', 'characters', 'character_episode'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy(
                $table === 'character_episode' ? 'character_id' : 'id'
            )->get()->toArray()])
            ->toJson();

        $afterFirst = $snapshot();

        $this->useCaseFor($this->catalog())->execute();

        $this->assertSame($afterFirst, $snapshot());
        $this->assertSame(2, SyncRun::count(), 'Each execution is still recorded separately.');
    }

    /**
     * A resource that stops short ends the run, because everything after it
     * depends on it being complete.
     */
    public function test_a_stopped_resource_makes_the_run_partial_and_skips_the_rest(): void
    {
        $catalog = (new FakeCatalog)
            ->serve('location', [
                [new LocationData(1, 'Earth (C-137)', 'Planet', null)],
                CatalogUnavailable::afterRetries('location', 3),
            ])
            ->serve('episode', [[new EpisodeData(1, 'Pilot', 'S01E01', null, null)]]);

        $run = $this->useCaseFor($catalog)->execute();

        $this->assertSame(SyncRunStatus::Partial, $run->status);
        $this->assertSame(['location'], array_values(array_unique($catalog->order)));
        $this->assertArrayNotHasKey('episode', $run->stats);
        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseCount('episodes', 0);
    }

    public function test_a_partial_run_keeps_what_it_wrote_and_says_why_it_stopped(): void
    {
        $catalog = (new FakeCatalog)->serve('location', [
            [new LocationData(1, 'Earth (C-137)', 'Planet', null)],
            CatalogUnavailable::respondedWith('location', 503),
        ]);

        $run = $this->useCaseFor($catalog)->execute();

        $this->assertSame(1, $run->stats['location']['records']);
        $this->assertStringContainsString('503', $run->stats['location']['stopped_because']);
    }

    /**
     * An abort is recorded before it is allowed to travel on, so the audit
     * trail survives without costing the stack trace.
     */
    public function test_an_abort_is_recorded_as_failed_and_still_raised(): void
    {
        $catalog = (new FakeCatalog)
            ->serve('location', [[new LocationData(1, 'Earth (C-137)', 'Planet', null)]])
            ->serve('episode', [[new EpisodeData(1, 'Pilot', 'S01E01', null, null)]])
            ->serve('character', [[
                new CharacterData(
                    externalId: 1,
                    name: 'Rick Sanchez',
                    status: CharacterStatus::Alive,
                    species: null,
                    type: null,
                    gender: CharacterGender::Male,
                    image: null,
                    originLocationExternalId: null,
                    currentLocationExternalId: null,
                    episodeExternalIds: [404],
                ),
            ]]);

        try {
            $this->useCaseFor($catalog)->execute();
            $this->fail('A reference to an unsynchronised episode should have aborted the run.');
        } catch (IncompleteCatalog) {
            // expected
        }

        $run = SyncRun::sole();

        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertStringContainsString('episode 404', $run->error);
        $this->assertNotNull($run->finished_at);
        // The resources that did finish keep their reports: an abort does not
        // erase what was already written and counted.
        $this->assertSame(1, $run->stats['location']['records']);
        $this->assertSame(1, $run->stats['episode']['records']);
        $this->assertArrayNotHasKey('character', $run->stats);
    }

    public function test_every_execution_is_recorded_even_when_nothing_changes(): void
    {
        $this->useCaseFor($this->catalog())->execute();
        $this->useCaseFor($this->catalog())->execute();

        $this->assertSame(2, SyncRun::count());
        $this->assertSame([SyncRunStatus::Completed, SyncRunStatus::Completed], SyncRun::pluck('status')->all());
    }
}
