<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Auth\ApiTokenGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the hand written token guard as a driver Laravel knows about.
 *
 * This one line is what makes the rest of the application indifferent to how
 * authentication works: from here on `auth:api` resolves to our guard, and
 * everything built on top of Laravel's contracts keeps working unchanged.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Auth::extend('api_token', static fn (Application $app): ApiTokenGuard => new ApiTokenGuard($app));
    }
}
