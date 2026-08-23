<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Favorites\AddFavoriteUseCase;
use App\Application\Favorites\ListFavoritesUseCase;
use App\Application\Favorites\RemoveFavoriteUseCase;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class FavoriteController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request, ListFavoritesUseCase $favorites): AnonymousResourceCollection
    {
        return CharacterResource::collection(
            $favorites->execute($request->user(), self::PER_PAGE),
        );
    }

    /**
     * Answers 204 whether or not anything changed. A caller that retries after
     * a dropped connection should not be told off for it.
     */
    public function store(Request $request, Character $character, AddFavoriteUseCase $add): Response
    {
        $add->execute($request->user(), $character);

        return response()->noContent();
    }

    public function destroy(Request $request, Character $character, RemoveFavoriteUseCase $remove): Response
    {
        $remove->execute($request->user(), $character);

        return response()->noContent();
    }
}
