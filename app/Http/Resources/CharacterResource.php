<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Character
 */
final class CharacterResource extends JsonResource
{
    /**
     * `id` is ours; `external_id` is the provider's, offered alongside so a
     * caller who knows the source can still line the two up. URLs use ours,
     * because that is the identifier this application controls.
     *
     * Relations appear only when they were loaded. Rendering one that was not
     * would issue a query per row while the response is being written, and the
     * response is the last place that should be talking to the database.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'status' => $this->status->value,
            'species' => $this->species,
            'type' => $this->type,
            'gender' => $this->gender->value,
            'image' => $this->image,
            'origin' => LocationResource::make($this->whenLoaded('origin')),
            'location' => LocationResource::make($this->whenLoaded('currentLocation')),
            'episodes' => EpisodeResource::collection($this->whenLoaded('episodes')),
        ];
    }
}
