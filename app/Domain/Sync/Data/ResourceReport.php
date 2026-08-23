<?php

declare(strict_types=1);

namespace App\Domain\Sync\Data;

/**
 * What happened to one resource during a synchronisation run.
 *
 * `stoppedBecause` is null when the resource was exhausted page by page, and
 * carries the reason when it was not. Records and pages count what was actually
 * committed, so a stopped resource still reports what it managed to write
 * rather than being written off entirely.
 */
final readonly class ResourceReport
{
    public function __construct(
        public string $resource,
        public int $pages,
        public int $records,
        public ?string $stoppedBecause = null,
    ) {}

    public function completed(): bool
    {
        return $this->stoppedBecause === null;
    }

    /**
     * @return array{pages: int, records: int, stopped_because: string|null}
     */
    public function toArray(): array
    {
        return [
            'pages' => $this->pages,
            'records' => $this->records,
            'stopped_because' => $this->stoppedBecause,
        ];
    }
}
