<?php

declare(strict_types=1);

namespace App\Infrastructure\RickAndMorty\Mappers;

use App\Domain\Catalog\Data\EpisodeData;
use App\Domain\Catalog\Exceptions\MalformedCatalogPayload;
use DateTimeImmutable;

/**
 * Turns one raw episode record from the provider into an EpisodeData.
 *
 * The provider sends dates the way a person writes them — "December 2, 2013" —
 * so they are parsed against that exact format rather than left to guesswork,
 * and the original string is carried alongside the parsed value.
 */
final class EpisodeMapper
{
    use ReadsRawRecords;

    private const RESOURCE = 'episode';

    /**
     * The leading "!" resets every field the format does not fill, so a date
     * lands on midnight instead of on the current time. Without it the same
     * record maps to a different value on every run — which is precisely what
     * a synchronisation that has to be idempotent cannot afford.
     */
    private const AIR_DATE_FORMAT = '!F j, Y';

    /**
     * @param  array<string, mixed>  $record
     *
     * @throws MalformedCatalogPayload
     */
    public function map(array $record): EpisodeData
    {
        $airDateRaw = $this->optionalText($record, 'air_date');

        return new EpisodeData(
            externalId: $this->requiredId(self::RESOURCE, $record),
            name: $this->requiredName(self::RESOURCE, $record),
            // The shape "S01E01" is not enforced. It is what the provider sends
            // today, but rejecting anything else would throw away a special or
            // a renumbered episode rather than store it.
            code: $this->optionalText($record, 'episode'),
            airDate: $this->parseAirDate($airDateRaw),
            airDateRaw: $airDateRaw,
        );
    }

    private function parseAirDate(?string $raw): ?DateTimeImmutable
    {
        if ($raw === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(self::AIR_DATE_FORMAT, $raw);

        if ($date === false) {
            return null;
        }

        // createFromFormat rolls "February 30" over into March and reports it
        // as a warning rather than a failure. Storing a date the provider never
        // sent is worse than storing none, so an overflow counts as a failed
        // parse: the date becomes null and the original string survives.
        $errors = DateTimeImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $date;
    }
}
