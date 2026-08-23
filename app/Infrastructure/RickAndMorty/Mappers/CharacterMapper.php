<?php

declare(strict_types=1);

namespace App\Infrastructure\RickAndMorty\Mappers;

use App\Domain\Catalog\Data\CharacterData;
use App\Domain\Catalog\Enums\CharacterGender;
use App\Domain\Catalog\Enums\CharacterStatus;
use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;

/**
 * Turns one raw character record from the provider into a CharacterData.
 *
 * This is the messiest of the three records. Nearly half the characters carry
 * an empty type, a third have no origin to point at, and every relation
 * arrives as a URL rather than an identifier.
 *
 * The two location references are treated differently from the episode list on
 * purpose. An empty origin or location URL is how the provider says "there is
 * nowhere to point", so it becomes null. An unreadable entry inside the episode
 * list is not a statement about the world, it is a broken record, and it is
 * raised rather than quietly dropped.
 */
final class CharacterMapper
{
    use ReadsRawRecords;

    private const RESOURCE = 'character';

    /**
     * @param  array<string, mixed>  $record
     *
     * @throws MalformedCatalogPayload
     */
    public function map(array $record): CharacterData
    {
        return new CharacterData(
            externalId: $this->requiredId(self::RESOURCE, $record),
            name: $this->requiredName(self::RESOURCE, $record),
            status: $this->status($record),
            species: $this->optionalText($record, 'species'),
            type: $this->optionalText($record, 'type'),
            gender: $this->gender($record),
            image: $this->optionalText($record, 'image'),
            originLocationExternalId: $this->locationReference($record, 'origin'),
            currentLocationExternalId: $this->locationReference($record, 'location'),
            episodeExternalIds: $this->episodeReferences($record),
        );
    }

    /**
     * `optionalText` collapses both "" and "unknown" into null. For a closed
     * set of values that collapse is not an absence: not knowing whether a
     * character is alive is itself one of the three possible answers, and 100
     * characters are in exactly that position.
     *
     * A value outside the set degrades to Unknown as well. The alternative —
     * failing the record — would break a whole synchronisation the day the
     * provider introduces a fourth status.
     *
     * @param  array<string, mixed>  $record
     */
    private function status(array $record): CharacterStatus
    {
        $value = $this->optionalText($record, 'status');

        if ($value === null) {
            return CharacterStatus::Unknown;
        }

        return CharacterStatus::tryFrom(strtolower($value)) ?? CharacterStatus::Unknown;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function gender(array $record): CharacterGender
    {
        $value = $this->optionalText($record, 'gender');

        if ($value === null) {
            return CharacterGender::Unknown;
        }

        return CharacterGender::tryFrom(strtolower($value)) ?? CharacterGender::Unknown;
    }

    /**
     * @param  array<string, mixed>  $record
     *
     * @throws MalformedCatalogPayload
     */
    private function locationReference(array $record, string $key): ?int
    {
        $reference = $record[$key] ?? null;

        if (! is_array($reference)) {
            return null;
        }

        $url = $reference['url'] ?? null;

        return $this->relatedId(self::RESOURCE, is_string($url) ? $url : null, 'location');
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<int>
     *
     * @throws MalformedCatalogPayload
     */
    private function episodeReferences(array $record): array
    {
        $references = $record['episode'] ?? [];

        if (! is_array($references)) {
            throw MalformedCatalogPayload::unexpectedStructure(self::RESOURCE, '"episode" is not a list');
        }

        $ids = [];

        foreach ($references as $position => $reference) {
            if (! is_string($reference) || trim($reference) === '') {
                throw MalformedCatalogPayload::unexpectedStructure(
                    self::RESOURCE,
                    sprintf('entry %s of "episode" is not a reference', (string) $position),
                );
            }

            $ids[] = $this->relatedId(self::RESOURCE, $reference, 'episode');
        }

        return array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null)));
    }
}
