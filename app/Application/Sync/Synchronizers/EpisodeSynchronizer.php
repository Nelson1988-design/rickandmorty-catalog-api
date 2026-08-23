<?php

declare(strict_types=1);

namespace App\Application\Sync\Synchronizers;

use App\Domain\Catalog\Contracts\CatalogProvider;
use App\Domain\Catalog\Data\EpisodeData;
use App\Domain\Catalog\Data\ResourcePage;
use App\Models\Episode;

final class EpisodeSynchronizer extends ResourceSynchronizer
{
    public function __construct(private readonly CatalogProvider $catalog) {}

    public function resource(): string
    {
        return 'episode';
    }

    protected function fetchPage(?string $cursor): ResourcePage
    {
        return $this->catalog->fetchEpisodes($cursor);
    }

    /**
     * @param  list<EpisodeData>  $items
     */
    protected function persist(array $items): void
    {
        if ($items === []) {
            return;
        }

        Episode::upsert(
            array_map(static fn (EpisodeData $episode): array => [
                'external_id' => $episode->externalId,
                'name' => $episode->name,
                'code' => $episode->code,
                'air_date' => $episode->airDate?->format('Y-m-d'),
                'air_date_raw' => $episode->airDateRaw,
            ], $items),
            ['external_id'],
            ['name', 'code', 'air_date', 'air_date_raw'],
        );
    }
}
