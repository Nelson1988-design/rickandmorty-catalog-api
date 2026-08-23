<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\Enums\CharacterStatus;

/**
 * What a caller asked the character list to narrow down to.
 *
 * Status arrives already turned into a domain enum: by the time a filter
 * reaches the application it is a value the domain recognises, not a string
 * somebody typed. An unrecognised one never gets this far.
 */
final readonly class CharacterFilters
{
    public function __construct(
        public ?string $name = null,
        public ?CharacterStatus $status = null,
        public ?string $species = null,
    ) {}
}
