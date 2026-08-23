<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

/**
 * One page of results from the catalog, plus whatever the provider needs in
 * order to hand over the next one.
 *
 * `nextCursor` is opaque. The domain must never parse it, build one, or assume
 * it is a page number, a URL or anything else: it is a token handed back to the
 * provider exactly as received. That is what allows the provider to follow its
 * own pagination scheme without the rest of the application knowing about it.
 *
 * @template TItem of object
 */
final readonly class ResourcePage
{
    /**
     * @param  list<TItem>  $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public int $totalCount,
    ) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
