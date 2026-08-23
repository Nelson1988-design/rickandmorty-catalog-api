<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Sync\SyncCatalogUseCase;
use App\Domain\Sync\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Illuminate\Console\Command;
use Throwable;

/**
 * The console entry point, and nothing more.
 *
 * It has no options on purpose. A flag to synchronise a single resource would
 * let a caller skip a step the next one depends on, which the foreign keys then
 * turn into an abort — a button that breaks a correctness constraint is not a
 * feature. The command synchronises everything, in order, or not at all.
 */
final class SyncCatalogCommand extends Command
{
    protected $signature = 'rickmorty:sync';

    protected $description = 'Synchronise the Rick and Morty catalogue into the local database';

    public function handle(SyncCatalogUseCase $sync): int
    {
        $this->components->info('Synchronising locations, then episodes, then characters.');

        try {
            $run = $sync->execute();
        } catch (Throwable $failure) {
            $this->components->error($failure->getMessage());
            $this->line('  The run was recorded as failed. Nothing already committed was rolled back.');

            return self::FAILURE;
        }

        $this->report($run);

        // Anything other than a complete run leaves a non-zero status, so a
        // scheduler or a pipeline notices a partial synchronisation instead of
        // reading it as success.
        return $run->status === SyncRunStatus::Completed ? self::SUCCESS : self::FAILURE;
    }

    private function report(SyncRun $run): void
    {
        $rows = [];

        foreach ($run->stats ?? [] as $resource => $stats) {
            $rows[] = [
                $resource,
                $stats['pages'],
                $stats['records'],
                $stats['stopped_because'] === null ? 'complete' : 'stopped',
            ];
        }

        $this->newLine();
        $this->table(['Resource', 'Pages', 'Records', 'Outcome'], $rows);

        foreach ($run->stats ?? [] as $resource => $stats) {
            if ($stats['stopped_because'] !== null) {
                $this->components->warn(sprintf('%s stopped: %s', $resource, $stats['stopped_because']));
            }
        }

        match ($run->status) {
            SyncRunStatus::Completed => $this->components->info(sprintf('Run #%d completed.', $run->id)),
            SyncRunStatus::Partial => $this->components->warn(sprintf(
                'Run #%d is partial: what was written is consistent, but the catalogue is incomplete.',
                $run->id,
            )),
            default => $this->components->error(sprintf('Run #%d failed.', $run->id)),
        };
    }
}
