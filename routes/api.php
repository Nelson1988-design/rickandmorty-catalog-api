<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CharacterController;
use App\Http\Controllers\Api\V1\EpisodeController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\LocationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // The catalogue is readable without a token. It mirrors a public source,
    // and putting a door in front of data that is already public would add a
    // step for every caller without protecting anything. What needs an identity
    // is what belongs to someone: their favourites.
    Route::get('characters', [CharacterController::class, 'index']);
    Route::get('characters/{character}', [CharacterController::class, 'show']);
    Route::get('episodes', [EpisodeController::class, 'index']);
    Route::get('episodes/{episode}', [EpisodeController::class, 'show']);
    Route::get('locations', [LocationController::class, 'index']);
    Route::get('locations/{location}', [LocationController::class, 'show']);

    Route::post('register', [AuthController::class, 'register']);

    // Rate limited because an authentication endpoint without a ceiling is an
    // invitation to guess passwords, and Laravel gives us the ceiling for free.
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth:api')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);

        // Favourites are their own collection rather than an action hanging off
        // a character: what is being changed is *my* list, and the character is
        // the argument.
        Route::get('favorites', [FavoriteController::class, 'index']);
        Route::post('favorites/{character}', [FavoriteController::class, 'store']);
        Route::delete('favorites/{character}', [FavoriteController::class, 'destroy']);
    });
});
