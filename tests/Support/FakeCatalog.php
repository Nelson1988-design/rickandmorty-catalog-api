<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Catalog\Contracts\CatalogProvider;
use App\Domain\Catalog\Data\ResourcePage;
use Throwable;

/**
 * A catalog that serves whatever the test scripted for it.
 *
 * Each resource is given a list of pages; a page is either the items to return
 * or the failure to raise when it is asked for. Cursors are handed out and
 * expected back exactly as the real provider does, so the pagination loop is
 * exercised for real without a single HTTP call.
 *
 * That this double can stand in at all is the point of the port. If it ever
 * stopped being possible, the abstraction would have leaked.
 */
final class FakeCatalog implements CatalogProvider
{
    /** @var array<string, list<list<object>|Throwable>> */
    private array $script = [];

    /** @var array<string, int> */
    public array $calls = ['character' => 0, 'episode' => 0, 'location' => 0];

    /** @var list<string> */
    public array $order = [];

    /**
     * @param  list<list<object>|Throwable>  $pages
     */
    public function serve(string $resource, array $pages): self
    {
        $this->script[$resource] = $pages;

        return $this;
    }

    public function fetchCharacters(?string $cursor = null): ResourcePage
    {
        return $this->page('character');
    }

    public function fetchEpisodes(?string $cursor = null): ResourcePage
    {
        return $this->page('episode');
    }

    public function fetchLocations(?string $cursor = null): ResourcePage
    {
        return $this->page('location');
    }

    private function page(string $resource): ResourcePage
    {
        $this->order[] = $resource;

        $index = $this->calls[$resource];
        $this->calls[$resource]++;

        $pages = $this->script[$resource] ?? [];
        $page = $pages[$index] ?? [];

        if ($page instanceof Throwable) {
            throw $page;
        }

        $remaining = isset($pages[$index + 1]);

        return new ResourcePage(
            items: $page,
            nextCursor: $remaining ? sprintf('cursor:%s:%d', $resource, $index + 1) : null,
            totalCount: count($pages),
        );
    }
}
