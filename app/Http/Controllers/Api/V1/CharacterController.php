<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Catalog\ListCharactersUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListCharactersRequest;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CharacterController extends Controller
{
    public const PER_PAGE = 20;

    public function index(ListCharactersRequest $request, ListCharactersUseCase $characters): AnonymousResourceCollection
    {
        return CharacterResource::collection(
            $characters->execute($request->filters(), self::PER_PAGE),
        );
    }

    /**
     * No use case behind this one: there is no decision to isolate, only a
     * record to load. A class that forwarded one call would be ceremony.
     */
    public function show(Character $character): CharacterResource
    {
        return new CharacterResource(
            $character->load(['origin', 'currentLocation', 'episodes']),
        );
    }
}
