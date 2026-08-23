<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * Gender of a character.
 *
 * Values are normalised to lower case so that they do not mirror the casing
 * of any particular provider. Translating the provider's own vocabulary into
 * these cases is the mapper's job.
 */
enum CharacterGender: string
{
    case Female = 'female';
    case Male = 'male';
    case Genderless = 'genderless';
    case Unknown = 'unknown';
}
