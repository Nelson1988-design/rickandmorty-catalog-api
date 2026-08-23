<?php

declare(strict_types=1);

namespace App\Application\Sync\Synchronizers;

use App\Domain\Catalog\Contracts\CatalogProvider;
use App\Domain\Catalog\Data\LocationData;
use App\Domain\Catalog\Data\ResourcePage;
use App\Models\Location;

final class LocationSynchronizer extends ResourceSynchronizer
{
    public function __construct(private readonly CatalogProvider $catalog) {}

    public function resource(): string
    {
        return 'location';
    }

    protected function fetchPage(?string $cursor): ResourcePage
    {
        return $this->catalog->fetchLocations($cursor);
    }

    /**
     * @param  list<LocationData>  $items
     */
    protected function persist(array $items): void
    {
        if ($items === []) {
            return;
        }

        Location::upsert(
            array_map(static fn (LocationData $location): array => [
                'external_id' => $location->externalId,
                'name' => $location->name,
                'type' => $location->type,
                'dimension' => $location->dimension,
            ], $items),
            ['external_id'],
            ['name', 'type', 'dimension'],
        );
    }
}
