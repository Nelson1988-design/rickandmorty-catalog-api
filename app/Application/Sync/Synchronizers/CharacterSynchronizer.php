<?php

declare(strict_types=1);

namespace App\Application\Sync\Synchronizers;

use App\Domain\Catalog\Contracts\CatalogProvider;
use App\Domain\Catalog\Data\CharacterData;
use App\Domain\Catalog\Data\ResourcePage;
use App\Domain\Sync\Exceptions\IncompleteCatalog;
use App\Models\Character;
use App\Models\Episode;
use App\Models\Location;

/**
 * The last resource, and the only one with references to resolve.
 *
 * Characters arrive carrying the provider's identifiers, not ours, so every
 * reference has to be translated. Both dictionaries are loaded once, before the
 * first page: two queries instead of the 1652 that resolving them one at a time
 * would cost. That shortcut is only available because locations and episodes
 * are already synchronised — the order is not a preference, it is what makes
 * the translation possible at all.
 */
final class CharacterSynchronizer extends ResourceSynchronizer
{
    /** @var array<int, int> */
    private array $locations = [];

    /** @var array<int, int> */
    private array $episodes = [];

    public function __construct(private readonly CatalogProvider $catalog) {}

    public function resource(): string
    {
        return 'character';
    }

    protected function prepare(): void
    {
        $this->locations = Location::pluck('id', 'external_id')->all();
        $this->episodes = Episode::pluck('id', 'external_id')->all();
    }

    protected function fetchPage(?string $cursor): ResourcePage
    {
        return $this->catalog->fetchCharacters($cursor);
    }

    /**
     * @param  list<CharacterData>  $items
     *
     * @throws IncompleteCatalog
     */
    protected function persist(array $items): void
    {
        if ($items === []) {
            return;
        }

        Character::upsert(
            array_map(fn (CharacterData $character): array => [
                'external_id' => $character->externalId,
                'name' => $character->name,
                'status' => $character->status->value,
                'species' => $character->species,
                'type' => $character->type,
                'gender' => $character->gender->value,
                'image' => $character->image,
                'origin_location_id' => $this->locationFor($character, $character->originLocationExternalId),
                'current_location_id' => $this->locationFor($character, $character->currentLocationExternalId),
            ], $items),
            ['external_id'],
            ['name', 'status', 'species', 'type', 'gender', 'image', 'origin_location_id', 'current_location_id'],
        );

        $this->attachEpisodes($items);
    }

    /**
     * `upsert` reports how many rows it touched but not which, so the internal
     * keys have to be read back before the pivot can be written. One query per
     * page, not one per character.
     *
     * @param  list<CharacterData>  $items
     *
     * @throws IncompleteCatalog
     */
    private function attachEpisodes(array $items): void
    {
        $stored = Character::whereIn('external_id', array_map(
            static fn (CharacterData $character): int => $character->externalId,
            $items,
        ))->get()->keyBy('external_id');

        foreach ($items as $character) {
            // sync() writes only the difference, so a second run over unchanged
            // data leaves the pivot exactly as it was.
            $stored[$character->externalId]->episodes()->sync($this->episodesFor($character));
        }
    }

    /**
     * @return list<int>
     *
     * @throws IncompleteCatalog
     */
    private function episodesFor(CharacterData $character): array
    {
        return array_map(function (int $externalId) use ($character): int {
            return $this->episodes[$externalId]
                ?? throw IncompleteCatalog::missingEpisode($character->externalId, $externalId);
        }, $character->episodeExternalIds);
    }

    /**
     * @throws IncompleteCatalog
     */
    private function locationFor(CharacterData $character, ?int $externalId): ?int
    {
        if ($externalId === null) {
            return null;
        }

        return $this->locations[$externalId]
            ?? throw IncompleteCatalog::missingLocation($character->externalId, $externalId);
    }
}
