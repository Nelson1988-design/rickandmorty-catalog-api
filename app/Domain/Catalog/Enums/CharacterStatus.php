<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * Life status of a character.
 *
 * Values are normalised to lower case so that they do not mirror the casing
 * of any particular provider. Translating the provider's own vocabulary into
 * these cases is the mapper's job.
 */
enum CharacterStatus: string
{
    case Alive = 'alive';
    case Dead = 'dead';
    case Unknown = 'unknown';
}
