<?php

declare(strict_types=1);

namespace App\Domain\Sync\Exceptions;

use RuntimeException;

/**
 * A character references something that is not in our database.
 *
 * Because synchronisation runs locations, then episodes, then characters, this
 * can only mean the pass before finished incomplete. It is raised rather than
 * skipped on purpose: dropping the reference would leave a character persisted
 * with a truncated set of relations and no error anywhere, which is the silent
 * loss the whole failure policy exists to prevent.
 *
 * The foreign keys would eventually catch it too, but a message that names the
 * missing record beats a constraint violation that only names a column.
 */
final class IncompleteCatalog extends RuntimeException
{
    public static function missingEpisode(int $characterExternalId, int $episodeExternalId): self
    {
        return new self(sprintf(
            'Character %d references episode %d, which is not in the database: the episode pass did not finish.',
            $characterExternalId,
            $episodeExternalId,
        ));
    }

    public static function missingLocation(int $characterExternalId, int $locationExternalId): self
    {
        return new self(sprintf(
            'Character %d references location %d, which is not in the database: the location pass did not finish.',
            $characterExternalId,
            $locationExternalId,
        ));
    }
}
