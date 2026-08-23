<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EpisodeResource;
use App\Models\Episode;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Episodes carry no filters, so neither endpoint has anything to isolate into a
 * use case. A class that only forwarded a call would be a name, not a layer.
 */
final class EpisodeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return EpisodeResource::collection(
            Episode::query()->orderBy('external_id')->paginate(CharacterController::PER_PAGE),
        );
    }

    public function show(Episode $episode): EpisodeResource
    {
        return new EpisodeResource($episode->load('characters'));
    }
}
