<?php

declare(strict_types=1);

namespace App\Application\Favorites;

use App\Models\Character;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListFavoritesUseCase
{
    /**
     * @return LengthAwarePaginator<int, Character>
     */
    public function execute(User $user, int $perPage): LengthAwarePaginator
    {
        return $user->favorites()
            // The locations travel with the characters instead of being fetched
            // one row at a time while the response is rendered.
            ->with(['origin', 'currentLocation'])
            // Most recently marked first, which is the order the pivot's own
            // timestamp exists to make possible.
            ->orderByPivot('created_at', 'desc')
            ->paginate($perPage);
    }
}
