<?php

declare(strict_types=1);

namespace App\Infrastructure\RickAndMorty\Mappers;

use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;

/**
 * Field readers shared by the three mappers.
 *
 * Only two fields are ever required — the external identifier and the name —
 * because without them a record cannot be reconciled or displayed. Everything
 * else degrades to null instead of aborting the page it arrived in.
 */
trait ReadsRawRecords
{
    /**
     * @param  array<string, mixed>  $record
     *
     * @throws MalformedCatalogPayload
     */
    protected function requiredId(string $resource, array $record): int
    {
        $value = $record['id'] ?? null;

        if ($value === null) {
            throw MalformedCatalogPayload::missingField($resource, 'id');
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw MalformedCatalogPayload::unexpectedStructure($resource, '"id" is not a whole number');
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $record
     *
     * @throws MalformedCatalogPayload
     */
    protected function requiredName(string $resource, array $record): string
    {
        $value = $record['name'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw MalformedCatalogPayload::missingField($resource, 'name');
        }

        return trim($value);
    }

    /**
     * Reads the identifier out of a reference the provider sends as a URL.
     *
     * An empty reference means the provider has nothing to point at — it says
     * so for 300 of the 826 characters — and yields null. A reference that is
     * present but unreadable does not: dropping it silently would lose a
     * relation without anyone noticing, so it is raised instead.
     *
     * The resource segment is checked as well. Without that check a reference
     * to /api/character/5 sitting in an origin field would quietly become
     * location 5, and the wrong row would look perfectly valid afterwards.
     *
     * @throws MalformedCatalogPayload
     */
    protected function relatedId(string $resource, ?string $url, string $expectedResource): ?int
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        if (preg_match('#/api/(?<resource>[a-z]+)/(?<id>\d+)/?$#', trim($url), $matches) !== 1) {
            throw MalformedCatalogPayload::unexpectedStructure(
                $resource,
                sprintf('no identifier can be read from the reference "%s"', $url),
            );
        }

        if ($matches['resource'] !== $expectedResource) {
            throw MalformedCatalogPayload::unexpectedStructure(
                $resource,
                sprintf(
                    'a %s reference was expected but "%s" points at a %s',
                    $expectedResource,
                    $url,
                    $matches['resource'],
                ),
            );
        }

        return (int) $matches['id'];
    }

    /**
     * Free text that the provider may leave blank.
     *
     * The provider writes "unknown" where it has no value, so that exact string
     * is treated as absence. The comparison is deliberately against the whole
     * trimmed value and not a substring search: "Unknown dimension" is the real
     * name of two locations, and a looser rule would throw it away.
     *
     * @param  array<string, mixed>  $record
     */
    protected function optionalText(array $record, string $field): ?string
    {
        $value = $record[$field] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strtolower($value) === 'unknown') {
            return null;
        }

        return $value;
    }
}
