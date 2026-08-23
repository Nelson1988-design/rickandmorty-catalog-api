<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

/**
 * A location as this application understands it.
 *
 * Residents are absent from this object, which is not the same as absent from
 * the model: a Location exposes its residents as the inverse of each
 * character's current location. What is avoided here is carrying the same
 * relationship a second time in the payload, where the two copies would have
 * every chance to disagree.
 */
final readonly class LocationData
{
    public function __construct(
        public int $externalId,
        public string $name,
        public ?string $type,
        public ?string $dimension,
    ) {}
}
