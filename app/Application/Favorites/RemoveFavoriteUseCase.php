<?php

declare(strict_types=1);

namespace App\Application\Favorites;

use App\Models\Character;
use App\Models\User;

final class RemoveFavoriteUseCase
{
    /**
     * Removing something that was not a favourite is not an error either. The
     * caller asked for a state — this character is not among my favourites —
     * and that state holds whether or not anything had to change.
     */
    public function execute(User $user, Character $character): void
    {
        $user->favorites()->detach($character->id);
    }
}
