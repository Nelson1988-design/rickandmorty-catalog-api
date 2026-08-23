<?php

declare(strict_types=1);

namespace App\Application\Favorites;

use App\Models\Character;
use App\Models\User;

final class AddFavoriteUseCase
{
    /**
     * Marking the same character twice is not an error, it is the same request
     * arriving twice — a retry after a dropped connection, a double tap. The
     * answer either way is that the character is a favourite.
     *
     * `syncWithoutDetaching` is given no pivot attributes on purpose: with
     * nothing to write, an existing row is left exactly as it was rather than
     * rewritten with a new timestamp, so a repeat changes nothing at all.
     */
    public function execute(User $user, Character $character): void
    {
        $user->favorites()->syncWithoutDetaching([$character->id]);
    }
}
