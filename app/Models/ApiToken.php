<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One issued API token.
 *
 * The plain text value exists only twice: in the response that hands it to the
 * client, and in the Authorization header it comes back on. What lives here is
 * its sha256, so a stolen dump authenticates nobody.
 *
 * Hashing is exposed as a single static method because it happens in two
 * places — when a token is issued and when one is looked up — and the two must
 * agree. Two copies of the same one-liner is how they stop agreeing.
 */
final class ApiToken extends Model
{
    /**
     * No updated_at: it would be written at exactly the moments last_used_at
     * is, and mean less.
     */
    const UPDATED_AT = null;

    /**
     * How stale `last_used_at` is allowed to get before it is rewritten.
     *
     * Updating it on every request would turn each read of the API into a
     * write. A few minutes of imprecision costs nothing to an audit trail and
     * removes practically all of those writes.
     */
    private const LAST_USED_PRECISION_MINUTES = 5;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'token',
        'last_used_at',
        'expires_at',
    ];

    public static function hash(string $plainText): string
    {
        return hash('sha256', $plainText);
    }

    /**
     * Issues a token for a user and returns the plain text value, which is the
     * only moment it is ever readable.
     */
    public static function issueFor(User $user): string
    {
        // Str::random draws from random_bytes, so these 40 characters are
        // cryptographically random rather than merely unpredictable-looking.
        $plainText = Str::random(40);

        $user->apiTokens()->create([
            'token' => self::hash($plainText),
            'expires_at' => now()->addHours((int) config('auth.api_token_lifetime_hours')),
        ]);

        return $plainText;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function markUsed(): void
    {
        if ($this->last_used_at !== null
            && $this->last_used_at->diffInMinutes(now()) < self::LAST_USED_PRECISION_MINUTES) {
            return;
        }

        $this->update(['last_used_at' => now()]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
