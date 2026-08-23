<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Episode extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'external_id',
        'name',
        'code',
        'air_date',
        'air_date_raw',
    ];

    /**
     * Readable from here, never written from here. The pivot is filled from the
     * character side, which is the side the provider populates for every single
     * record, so the relationship has exactly one writer.
     *
     * @return BelongsToMany<Character, $this>
     */
    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Character::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'air_date' => 'date',
        ];
    }
}
