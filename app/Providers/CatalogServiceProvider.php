<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Contracts\CatalogProvider;
use App\Infrastructure\RickAndMorty\RickAndMortyProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Where the catalog port is wired to the adapter that implements it.
 *
 * This single line is what keeps the rest of the application from knowing that
 * Rick and Morty is the source: everything else asks for a CatalogProvider and
 * receives whatever is bound here. Replacing the source means changing this
 * file and nothing else.
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CatalogProvider::class, RickAndMortyProvider::class);
    }
}
