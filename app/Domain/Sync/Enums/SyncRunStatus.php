<?php

declare(strict_types=1);

namespace App\Domain\Sync\Enums;

/**
 * How a synchronisation run ended.
 *
 * `Partial` is the case that matters: the run finished and everything it wrote
 * is consistent, but at least one resource stopped before exhausting its pages.
 * Without a separate name for it, a partial run would have to lie in one
 * direction or the other — reporting success while the catalogue is incomplete,
 * or reporting failure while the data written is perfectly sound.
 */
enum SyncRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
}
