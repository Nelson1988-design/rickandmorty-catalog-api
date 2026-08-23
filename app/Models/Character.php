<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\Enums\CharacterGender;
use App\Domain\Catalog\Enums\CharacterStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Character extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'external_id',
        'name',
        'status',
        'species',
        'type',
        'gender',
        'image',
        'origin_location_id',
        'current_location_id',
    ];

    /**
     * Where the character comes from. Nullable: the provider reports an unknown
     * origin with an empty URL, and it does so for 300 of the 826 characters.
     *
     * @return BelongsTo<Location, $this>
     */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    /**
     * Where the character is now. This is the reference residency is derived
     * from — see Location::residents().
     *
     * @return BelongsTo<Location, $this>
     */
    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    /**
     * @return BelongsToMany<Episode, $this>
     */
    public function episodes(): BelongsToMany
    {
        return $this->belongsToMany(Episode::class);
    }

    /**
     * The enums come from the domain, not from here. Persistence is allowed to
     * depend on the domain; the dependency in the other direction is the one
     * that would break the arrangement.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CharacterStatus::class,
            'gender' => CharacterGender::class,
        ];
    }
}
