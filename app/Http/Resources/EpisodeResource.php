<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Episode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Episode
 */
final class EpisodeResource extends JsonResource
{
    /**
     * Both the parsed date and the string the provider sent are exposed. The
     * first is null whenever the second could not be read, and keeping the
     * original visible is what makes that difference explainable to whoever is
     * looking at the data rather than a silent gap.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'code' => $this->code,
            'air_date' => $this->air_date?->toDateString(),
            'air_date_raw' => $this->air_date_raw,
            'characters' => CharacterResource::collection($this->whenLoaded('characters')),
        ];
    }
}
