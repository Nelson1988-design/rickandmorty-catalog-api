<?php

declare(strict_types=1);

namespace App\Infrastructure\RickAndMorty\Mappers;

use App\Domain\Catalog\Data\LocationData;
use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;

/**
 * Turns one raw location record from the provider into a LocationData.
 *
 * Residents are ignored on purpose: residency is derived from each character's
 * current location, and holding the same relationship on both sides only gives
 * the two of them a chance to disagree.
 *
 * A record this mapper cannot read raises an exception rather than being
 * skipped. Deciding what to do about it — abort, or record the failure and
 * carry on — is the synchronisation command's call, not the mapper's.
 */
final class LocationMapper
{
    use ReadsRawRecords;

    private const RESOURCE = 'location';

    /**
     * @param  array<string, mixed>  $record
     *
     * @throws MalformedCatalogPayload
     */
    public function map(array $record): LocationData
    {
        return new LocationData(
            externalId: $this->requiredId(self::RESOURCE, $record),
            name: $this->requiredName(self::RESOURCE, $record),
            type: $this->optionalText($record, 'type'),
            dimension: $this->optionalText($record, 'dimension'),
        );
    }
}
