<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;

/**
 * The external catalog answered successfully, but the body was not shaped the
 * way this application requires.
 *
 * Raised only for the fields without which a record cannot exist: the external
 * identifier and the name. Every other missing or empty field degrades to null
 * rather than aborting the whole page.
 */
final class MalformedCatalogPayload extends RuntimeException
{
    public static function missingField(string $resource, string $field): self
    {
        return new self(
            sprintf('The catalog payload for "%s" is missing the required field "%s".', $resource, $field),
        );
    }

    public static function unexpectedStructure(string $resource, string $detail): self
    {
        return new self(
            sprintf('The catalog payload for "%s" is not shaped as expected: %s.', $resource, $detail),
        );
    }
}
