<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Contracts;

use App\Domain\Catalog\Data\CharacterData;
use App\Domain\Catalog\Data\EpisodeData;
use App\Domain\Catalog\Data\LocationData;
use App\Domain\Catalog\Data\ResourcePage;
use App\Domain\Catalog\Exceptions\CatalogUnavailable;
use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;

/**
 * The port through which this application reads an external catalog.
 *
 * Everything on this side of the boundary is ours: our data objects, our
 * exceptions, our vocabulary. No implementation detail of any provider —
 * URLs, status codes, JSON shapes, pagination schemes — is allowed to appear
 * in this signature. That restriction is the whole point of the interface.
 *
 * Every method walks its collection page by page. Pass null to start, then
 * hand back the `nextCursor` of the page you just received until it is null.
 */
interface CatalogProvider
{
    /**
     * @param  string|null  $cursor  Opaque token from a previous page, or null to start.
     * @return ResourcePage<CharacterData>
     *
     * @throws CatalogUnavailable The catalog could not be reached.
     * @throws MalformedCatalogPayload The catalog answered with an unusable body.
     */
    public function fetchCharacters(?string $cursor = null): ResourcePage;

    /**
     * @param  string|null  $cursor  Opaque token from a previous page, or null to start.
     * @return ResourcePage<EpisodeData>
     *
     * @throws CatalogUnavailable The catalog could not be reached.
     * @throws MalformedCatalogPayload The catalog answered with an unusable body.
     */
    public function fetchEpisodes(?string $cursor = null): ResourcePage;

    /**
     * @param  string|null  $cursor  Opaque token from a previous page, or null to start.
     * @return ResourcePage<LocationData>
     *
     * @throws CatalogUnavailable The catalog could not be reached.
     * @throws MalformedCatalogPayload The catalog answered with an unusable body.
     */
    public function fetchLocations(?string $cursor = null): ResourcePage;
}
