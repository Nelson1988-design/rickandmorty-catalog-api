<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Contracts\CatalogProvider;
use App\Domain\Catalog\Data\EpisodeData;
use App\Domain\Catalog\Data\LocationData;
use App\Domain\Catalog\Exceptions\CatalogUnavailable;
use App\Domain\Sync\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeCatalog;
use Tests\TestCase;

final class SyncCatalogCommandTest extends TestCase
{
    use RefreshDatabase;

    private function bind(FakeCatalog $catalog): void
    {
        $this->app->instance(CatalogProvider::class, $catalog);
    }

    public function test_a_complete_run_exits_successfully(): void
    {
        $this->bind((new FakeCatalog)
            ->serve('location', [[new LocationData(1, 'Earth (C-137)', 'Planet', null)]])
            ->serve('episode', [[new EpisodeData(1, 'Pilot', 'S01E01', null, null)]])
            ->serve('character', [[]]));

        $this->artisan('rickmorty:sync')->assertExitCode(0);

        $this->assertSame(SyncRunStatus::Completed, SyncRun::sole()->status);
    }

    /**
     * A partial run is not a success. A scheduler reading only the exit code
     * has to be able to tell the difference.
     */
    public function test_a_partial_run_exits_with_a_failure_code(): void
    {
        $this->bind((new FakeCatalog)->serve('location', [
            [new LocationData(1, 'Earth (C-137)', 'Planet', null)],
            CatalogUnavailable::afterRetries('location', 3),
        ]));

        $this->artisan('rickmorty:sync')->assertExitCode(1);

        $this->assertSame(SyncRunStatus::Partial, SyncRun::sole()->status);
    }

    public function test_it_reports_what_each_resource_wrote(): void
    {
        $this->bind((new FakeCatalog)
            ->serve('location', [[new LocationData(1, 'Earth (C-137)', 'Planet', null)]])
            ->serve('episode', [[new EpisodeData(1, 'Pilot', 'S01E01', null, null)]])
            ->serve('character', [[]]));

        $this->artisan('rickmorty:sync')
            ->expectsOutputToContain('location')
            ->expectsOutputToContain('episode')
            ->assertExitCode(0);
    }

    /**
     * The command has no options. One that let a caller synchronise characters
     * without episodes would break a correctness constraint by design.
     */
    public function test_the_command_takes_no_options(): void
    {
        $definition = $this->app->make(Kernel::class)
            ->all()['rickmorty:sync']
            ->getDefinition();

        $ownOptions = array_diff(
            array_keys($definition->getOptions()),
            ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'],
        );

        $this->assertSame([], array_values($ownOptions), 'The command should declare no options of its own.');
        $this->assertSame([], array_keys($definition->getArguments()), 'The command should declare no arguments either.');
    }
}
