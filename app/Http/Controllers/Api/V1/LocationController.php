<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class LocationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return LocationResource::collection(
            Location::query()->orderBy('external_id')->paginate(CharacterController::PER_PAGE),
        );
    }

    /**
     * The residents are the point of this endpoint: they are the relation the
     * brief asks Location to expose, derived from each character's current
     * location and from nowhere else. Thirty-two locations have none, and the
     * answer for those is an empty list rather than an error.
     */
    public function show(Location $location): LocationResource
    {
        return new LocationResource($location->load('residents'));
    }
}
