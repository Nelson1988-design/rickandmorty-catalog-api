<?php

declare(strict_types=1);

namespace App\Application\Sync\Synchronizers;

use App\Domain\Catalog\Data\ResourcePage;
use App\Domain\Catalog\Exceptions\CatalogUnavailable;
use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;
use App\Domain\Sync\Data\ResourceReport;
use Illuminate\Support\Facades\DB;

/**
 * Walks one resource page by page and writes what it finds.
 *
 * The loop is identical for the three resources; only where the page comes
 * from and how it is stored differ, so those are the two things a subclass
 * supplies.
 *
 * Two rules shape it. The page is fetched **outside** any transaction, because
 * holding database locks open across network calls is how a synchronisation
 * takes a table hostage. And the page is the unit of atomicity: everything in
 * it is written together or not at all, which bounds the damage of a failure to
 * twenty records instead of the whole catalogue.
 */
abstract class ResourceSynchronizer
{
    abstract public function resource(): string;

    /**
     * @throws CatalogUnavailable
     * @throws MalformedCatalogPayload
     */
    abstract protected function fetchPage(?string $cursor): ResourcePage;

    /**
     * Runs inside a transaction, once per page.
     *
     * @param  list<object>  $items
     */
    abstract protected function persist(array $items): void;

    /**
     * Anything the resource needs loaded before the first page.
     */
    protected function prepare(): void {}

    public function synchronize(): ResourceReport
    {
        $this->prepare();

        $cursor = null;
        $pages = 0;
        $records = 0;

        do {
            try {
                $page = $this->fetchPage($cursor);
            } catch (CatalogUnavailable|MalformedCatalogPayload $failure) {
                // A page that cannot be downloaded or cannot be read ends this
                // resource. There is no way around it either: with cursor
                // pagination the only route to the next page is the token that
                // came inside this one, so a missing page breaks the chain.
                // Everything committed so far stays, and the reason travels
                // back in the report.
                return new ResourceReport($this->resource(), $pages, $records, $failure->getMessage());
            }

            // Anything thrown from here on — a foreign key violation, a
            // reference to a record that was never synchronised — is
            // deliberately not caught. Those failures say the run itself is
            // built on incomplete ground, and continuing would only spread it.
            DB::transaction(fn () => $this->persist($page->items));

            $pages++;
            $records += count($page->items);
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return new ResourceReport($this->resource(), $pages, $records);
    }
}
