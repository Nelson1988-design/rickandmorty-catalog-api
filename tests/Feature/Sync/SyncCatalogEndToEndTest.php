<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Enums\CharacterStatus;
use App\Domain\Sync\Enums\SyncRunStatus;
use App\Models\Character;
use App\Models\Episode;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The whole chain, with nothing faked but the network.
 *
 * Every other test in the suite replaces the port with a double, which is the
 * right thing to do for each piece in isolation — but it leaves one seam
 * uncovered: the wiring between the real adapter and the synchronisation layer.
 * This is the test that walks command → provider → mappers → database, using
 * payloads shaped exactly like the ones the provider really sends.
 */
final class SyncCatalogEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://rickandmortyapi.com/api';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rickandmorty.api_url', self::API);
        config()->set('rickandmorty.retry_times', 3);
        config()->set('rickandmorty.retry_delay', 0);
        config()->set('rickandmorty.page_delay', 0);

        Http::preventStrayRequests();
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function page(array $results, ?string $next): array
    {
        return [
            'info' => ['count' => count($results), 'pages' => 1, 'next' => $next, 'prev' => null],
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawLocation(int $id, string $name, string $dimension = 'Dimension C-137'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'type' => 'Planet',
            'dimension' => $dimension,
            'residents' => [self::API.'/character/1'],
            'url' => self::API.'/location/'.$id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawEpisode(int $id, string $name, string $code): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'air_date' => 'December 2, 2013',
            'episode' => $code,
            'characters' => [self::API.'/character/1'],
            'url' => self::API.'/episode/'.$id,
        ];
    }

    /**
     * @param  list<int>  $episodes
     * @return array<string, mixed>
     */
    private function rawCharacter(int $id, string $name, ?int $origin, ?int $current, array $episodes): array
    {
        $reference = static fn (?int $locationId): array => $locationId === null
            ? ['name' => 'unknown', 'url' => '']
            : ['name' => 'Somewhere', 'url' => self::API.'/location/'.$locationId];

        return [
            'id' => $id,
            'name' => $name,
            'status' => 'Alive',
            'species' => 'Human',
            // Empty in half the real catalogue.
            'type' => '',
            'gender' => 'Male',
            'origin' => $reference($origin),
            'location' => $reference($current),
            'image' => self::API.'/character/avatar/'.$id.'.jpeg',
            'episode' => array_map(fn (int $episodeId): string => self::API.'/episode/'.$episodeId, $episodes),
            'url' => self::API.'/character/'.$id,
        ];
    }

    private function fakeTheWholeCatalog(): void
    {
        Http::fake([
            self::API.'/location' => Http::response($this->page([
                $this->rawLocation(1, 'Earth (C-137)'),
            ], self::API.'/location?page=2')),

            self::API.'/location?page=2' => Http::response($this->page([
                $this->rawLocation(3, 'Citadel of Ricks', 'unknown'),
            ], null)),

            self::API.'/episode' => Http::response($this->page([
                $this->rawEpisode(1, 'Pilot', 'S01E01'),
                $this->rawEpisode(2, 'Lawnmower Dog', 'S01E02'),
            ], null)),

            self::API.'/character' => Http::response($this->page([
                $this->rawCharacter(1, 'Rick Sanchez', origin: 1, current: 3, episodes: [1, 2]),
            ], self::API.'/character?page=2')),

            self::API.'/character?page=2' => Http::response($this->page([
                $this->rawCharacter(8, 'Adjudicator Rick', origin: null, current: null, episodes: [1]),
            ], null)),
        ]);
    }

    public function test_the_command_walks_the_whole_chain_into_the_database(): void
    {
        $this->fakeTheWholeCatalog();

        $this->artisan('rickmorty:sync')->assertExitCode(0);

        $this->assertDatabaseCount('locations', 2);
        $this->assertDatabaseCount('episodes', 2);
        $this->assertDatabaseCount('characters', 2);
        $this->assertDatabaseCount('character_episode', 3);

        $this->assertSame(SyncRunStatus::Completed, SyncRun::sole()->status);
    }

    public function test_the_provider_quirks_survive_the_whole_journey(): void
    {
        $this->fakeTheWholeCatalog();

        $this->artisan('rickmorty:sync');

        $rick = Character::where('external_id', 1)->firstOrFail();
        $adjudicator = Character::where('external_id', 8)->firstOrFail();
        $pilot = Episode::where('external_id', 1)->firstOrFail();

        // "" became null rather than an empty string in the column.
        $this->assertNull($rick->type);

        // An empty origin URL became no reference at all.
        $this->assertNull($adjudicator->origin_location_id);
        $this->assertNull($adjudicator->current_location_id);

        // "unknown" as a dimension is absence; "Alive" became a domain enum.
        $this->assertNull($rick->currentLocation->dimension);
        $this->assertSame(CharacterStatus::Alive, $rick->status);

        // The human date was parsed and the original string kept.
        $this->assertSame('2013-12-02', $pilot->air_date->format('Y-m-d'));
        $this->assertSame('December 2, 2013', $pilot->air_date_raw);

        // References arrived as URLs and left as local keys.
        $this->assertSame('Earth (C-137)', $rick->origin->name);
        $this->assertCount(2, $rick->episodes);
    }

    /**
     * The requirement of the brief, end to end and through the real adapter.
     */
    public function test_running_the_command_twice_leaves_the_database_identical(): void
    {
        $this->fakeTheWholeCatalog();

        $this->artisan('rickmorty:sync');

        $snapshot = fn (): string => collect(['locations', 'episodes', 'characters'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toArray()])
            ->put('character_episode', DB::table('character_episode')->orderBy('character_id')->orderBy('episode_id')->get()->toArray())
            ->toJson();

        $afterFirst = $snapshot();

        $this->artisan('rickmorty:sync')->assertExitCode(0);

        $this->assertSame($afterFirst, $snapshot());
        $this->assertSame(2, SyncRun::count());
    }

    /**
     * A page that keeps failing after every retry stops its resource. What came
     * before it is committed, the run says why, and the exit code is not zero.
     */
    public function test_a_resource_that_stops_leaves_a_partial_run_with_what_it_wrote(): void
    {
        Http::fake([
            self::API.'/location' => Http::response($this->page([$this->rawLocation(1, 'Earth (C-137)')], null)),
            self::API.'/episode' => Http::response($this->page([$this->rawEpisode(1, 'Pilot', 'S01E01')], null)),

            self::API.'/character' => Http::response($this->page([
                $this->rawCharacter(1, 'Rick Sanchez', origin: 1, current: 1, episodes: [1]),
            ], self::API.'/character?page=2')),

            self::API.'/character?page=2' => Http::response('upstream exploded', 500),
        ]);

        $this->artisan('rickmorty:sync')->assertExitCode(1);

        $run = SyncRun::sole();

        $this->assertSame(SyncRunStatus::Partial, $run->status);
        $this->assertSame(1, $run->stats['character']['pages']);
        $this->assertSame(1, $run->stats['character']['records']);
        $this->assertStringContainsString('500', $run->stats['character']['stopped_because']);

        // Everything before the failure is still there, and consistent.
        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseCount('episodes', 1);
        $this->assertDatabaseCount('characters', 1);
        $this->assertDatabaseCount('character_episode', 1);
    }

    /**
     * The rate limit answers in plain text rather than JSON, which the payload
     * validator has to report as an unusable body instead of an empty page.
     */
    public function test_a_rate_limited_resource_stops_instead_of_syncing_nothing_quietly(): void
    {
        Http::fake([
            self::API.'/location' => Http::response('error code: 1015', 429),
        ]);

        $this->artisan('rickmorty:sync')->assertExitCode(1);

        $run = SyncRun::sole();

        $this->assertSame(SyncRunStatus::Partial, $run->status);
        $this->assertSame(0, $run->stats['location']['records']);
        $this->assertStringContainsString('429', $run->stats['location']['stopped_because']);
        $this->assertArrayNotHasKey('episode', $run->stats);
    }
}
