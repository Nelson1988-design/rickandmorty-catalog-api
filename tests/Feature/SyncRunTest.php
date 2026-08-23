<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sync\Data\ResourceReport;
use App\Domain\Sync\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SyncRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_run_starts_in_the_running_state_with_no_end(): void
    {
        $run = SyncRun::create([
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
        ]);

        $stored = $run->fresh();

        $this->assertSame(SyncRunStatus::Running, $stored->status);
        $this->assertNull($stored->finished_at);
        $this->assertNull($stored->stats);
        $this->assertNull($stored->error);
    }

    public function test_it_records_what_each_resource_managed_to_write(): void
    {
        $run = SyncRun::create([
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
        ]);

        $run->recordReports([
            new ResourceReport('location', pages: 7, records: 126),
            new ResourceReport('episode', pages: 3, records: 51),
            new ResourceReport('character', pages: 42, records: 826),
        ]);

        $run->status = SyncRunStatus::Completed;
        $run->finished_at = now();
        $run->save();

        $stats = $run->fresh()->stats;

        $this->assertSame(126, $stats['location']['records']);
        $this->assertSame(42, $stats['character']['pages']);
        $this->assertNull($stats['episode']['stopped_because']);
    }

    /**
     * The state that exists so a run does not have to lie. Everything written
     * is consistent, but the catalogue is not complete.
     */
    public function test_a_partial_run_keeps_what_was_written_and_says_why_it_stopped(): void
    {
        $run = SyncRun::create([
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
        ]);

        $run->recordReports([
            new ResourceReport('location', pages: 7, records: 126),
            new ResourceReport('episode', pages: 3, records: 51),
            new ResourceReport('character', pages: 16, records: 320, stoppedBecause: 'The catalog did not answer for "character" after 3 attempts.'),
        ]);

        $run->status = SyncRunStatus::Partial;
        $run->finished_at = now();
        $run->save();

        $stats = $run->fresh()->stats;

        $this->assertSame(SyncRunStatus::Partial, $run->fresh()->status);
        $this->assertSame(320, $stats['character']['records']);
        $this->assertStringContainsString('after 3 attempts', $stats['character']['stopped_because']);
        $this->assertNull($stats['location']['stopped_because']);
    }

    public function test_a_report_knows_whether_its_resource_finished(): void
    {
        $this->assertTrue((new ResourceReport('location', 7, 126))->completed());
        $this->assertFalse((new ResourceReport('character', 16, 320, 'network is gone'))->completed());
    }

    public function test_the_run_table_uses_explicit_marks_instead_of_timestamps(): void
    {
        $this->assertTrue(Schema::hasColumn('sync_runs', 'started_at'));
        $this->assertTrue(Schema::hasColumn('sync_runs', 'finished_at'));
        $this->assertFalse(Schema::hasColumn('sync_runs', 'created_at'));
        $this->assertFalse(Schema::hasColumn('sync_runs', 'updated_at'));
    }
}
