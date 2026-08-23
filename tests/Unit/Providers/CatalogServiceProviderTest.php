<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Domain\Catalog\Contracts\CatalogProvider;
use App\Domain\Catalog\Data\ResourcePage;
use App\Infrastructure\RickAndMorty\RickAndMortyProvider;
use Tests\TestCase;

final class CatalogServiceProviderTest extends TestCase
{
    public function test_the_catalog_port_resolves_to_the_rick_and_morty_adapter(): void
    {
        $this->assertInstanceOf(RickAndMortyProvider::class, app(CatalogProvider::class));
    }

    /**
     * The point of the port, stated as a test: a different source can be put in
     * place without any consumer noticing. If this ever stops passing, the
     * abstraction has leaked somewhere.
     */
    public function test_the_source_can_be_replaced_without_touching_its_consumers(): void
    {
        $elsewhere = new class implements CatalogProvider
        {
            public function fetchCharacters(?string $cursor = null): ResourcePage
            {
                return new ResourcePage([], null, 0);
            }

            public function fetchEpisodes(?string $cursor = null): ResourcePage
            {
                return new ResourcePage([], null, 0);
            }

            public function fetchLocations(?string $cursor = null): ResourcePage
            {
                return new ResourcePage([], null, 0);
            }
        };

        $this->app->instance(CatalogProvider::class, $elsewhere);

        $this->assertSame($elsewhere, app(CatalogProvider::class));
        $this->assertSame(0, app(CatalogProvider::class)->fetchCharacters()->totalCount);
    }
}
