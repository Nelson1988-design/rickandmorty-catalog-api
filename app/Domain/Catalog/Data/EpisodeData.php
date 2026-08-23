<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use DateTimeImmutable;

/**
 * An episode as this application understands it.
 *
 * The provider calls the episode code "episode", which collides with the name
 * of the resource itself. It is renamed to `code` here to remove the ambiguity.
 *
 * `airDate` is the parsed date and `airDateRaw` the original string. Keeping
 * both means a change in the provider's date format degrades to a null date
 * instead of losing the information altogether.
 *
 * The characters of an episode are absent for the same reason a location does
 * not carry its residents: an Episode still exposes its characters, but the
 * many-to-many is written from the character side, which is the side the
 * provider fills in for every single record.
 */
final readonly class EpisodeData
{
    public function __construct(
        public int $externalId,
        public string $name,
        public ?string $code,
        public ?DateTimeImmutable $airDate,
        public ?string $airDateRaw,
    ) {}
}
