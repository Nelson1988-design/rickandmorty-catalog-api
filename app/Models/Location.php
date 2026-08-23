<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Location extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'external_id',
        'name',
        'type',
        'dimension',
    ];

    /**
     * The characters currently here.
     *
     * Residency is the inverse of a character's *current* location and nothing
     * else: having come from somewhere does not make you a resident of it. The
     * relationship lives only on this side, derived rather than stored twice.
     *
     * @return HasMany<Character, $this>
     */
    public function residents(): HasMany
    {
        return $this->hasMany(Character::class, 'current_location_id');
    }
}
