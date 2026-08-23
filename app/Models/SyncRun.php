<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Sync\Data\ResourceReport;
use App\Domain\Sync\Enums\SyncRunStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * The record of one synchronisation run.
 *
 * Unlike the catalogue tables, this one is ours: it has a life of its own, a
 * beginning and an end, and it exists precisely so that what happened during a
 * run outlives the terminal it was printed to.
 */
final class SyncRun extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'status',
        'started_at',
        'finished_at',
        'stats',
        'error',
    ];

    /**
     * @param  list<ResourceReport>  $reports
     */
    public function recordReports(array $reports): void
    {
        $stats = [];

        foreach ($reports as $report) {
            $stats[$report->resource] = $report->toArray();
        }

        $this->stats = $stats;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SyncRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'stats' => 'array',
        ];
    }
}
