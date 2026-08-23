<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The external catalog could not be reached, or answered with an error that
 * the caller cannot do anything about.
 *
 * This is the domain's way of saying "the source is down". It carries no HTTP
 * vocabulary on purpose: the adapter decides what counts as unavailable.
 */
final class CatalogUnavailable extends RuntimeException
{
    public static function afterRetries(string $resource, int $attempts, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('The catalog did not answer for "%s" after %d attempts.', $resource, $attempts),
            previous: $previous,
        );
    }

    public static function respondedWith(string $resource, int $status): self
    {
        return new self(
            sprintf('The catalog answered with an unrecoverable status %d while fetching "%s".', $status, $resource),
        );
    }
}
