<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use App\Domain\Catalog\Enums\CharacterGender;
use App\Domain\Catalog\Enums\CharacterStatus;

/**
 * A character as this application understands it.
 *
 * Relations travel as the provider's identifiers, not as ours: at the time a
 * page is mapped, the referenced locations and episodes may not have been
 * persisted yet. Resolving external identifiers into local keys belongs to the
 * synchronisation layer, which is the only place that knows both sides.
 *
 * A character may reference no location at all — the provider reports unknown
 * origins with an empty URL — so both location fields are nullable.
 */
final readonly class CharacterData
{
    /**
     * @param  list<int>  $episodeExternalIds
     */
    public function __construct(
        public int $externalId,
        public string $name,
        public CharacterStatus $status,
        public ?string $species,
        public ?string $type,
        public CharacterGender $gender,
        public ?string $image,
        public ?int $originLocationExternalId,
        public ?int $currentLocationExternalId,
        public array $episodeExternalIds,
    ) {}
}
