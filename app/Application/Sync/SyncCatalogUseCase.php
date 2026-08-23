<?php

declare(strict_types=1);

namespace App\Application\Sync;

use App\Application\Sync\Synchronizers\CharacterSynchronizer;
use App\Application\Sync\Synchronizers\EpisodeSynchronizer;
use App\Application\Sync\Synchronizers\LocationSynchronizer;
use App\Application\Sync\Synchronizers\ResourceSynchronizer;
use App\Domain\Sync\Data\ResourceReport;
use App\Domain\Sync\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Throwable;

/**
 * Runs the three resources in the only order they can run in and records what
 * happened.
 *
 * Locations and episodes come first because characters reference them: without
 * both in place, every character reference would be unresolvable. The order is
 * a correctness constraint, not a preference, and it is expressed here as a
 * literal list so it cannot drift.
 */
final class SyncCatalogUseCase
{
    public function __construct(
        private readonly LocationSynchronizer $locations,
        private readonly EpisodeSynchronizer $episodes,
        private readonly CharacterSynchronizer $characters,
    ) {}

    public function execute(): SyncRun
    {
        $run = SyncRun::create([
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
        ]);

        $reports = [];

        try {
            foreach ($this->inOrder() as $synchronizer) {
                $report = $synchronizer->synchronize();
                $reports[] = $report;

                // A resource that stopped short ends the run. Everything after
                // it in this list depends on it being complete, so carrying on
                // would only guarantee a failure further down — and the reason
                // would then point at the consequence instead of the cause.
                if (! $report->completed()) {
                    break;
                }
            }
        } catch (Throwable $failure) {
            // The run is recorded before the exception is allowed to continue.
            // Swallowing it here would trade a stack trace for a status column;
            // this way the audit trail and the trace both survive.
            $this->close($run, $reports, SyncRunStatus::Failed, $failure->getMessage());

            throw $failure;
        }

        $everyResourceFinished = count($reports) === 3
            && array_reduce($reports, static fn (bool $all, $report): bool => $all && $report->completed(), true);

        $this->close(
            $run,
            $reports,
            $everyResourceFinished ? SyncRunStatus::Completed : SyncRunStatus::Partial,
        );

        return $run;
    }

    /**
     * @return list<ResourceSynchronizer>
     */
    private function inOrder(): array
    {
        return [$this->locations, $this->episodes, $this->characters];
    }

    /**
     * @param  list<ResourceReport>  $reports
     */
    private function close(SyncRun $run, array $reports, SyncRunStatus $status, ?string $error = null): void
    {
        $run->recordReports($reports);
        $run->status = $status;
        $run->error = $error;
        $run->finished_at = now();
        $run->save();
    }
}
