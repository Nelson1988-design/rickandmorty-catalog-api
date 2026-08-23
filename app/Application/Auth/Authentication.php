<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\User;

/**
 * Who was authenticated, and the token they were handed.
 *
 * The plain text token exists here and nowhere else afterwards: it is written
 * into one response and then only its hash survives.
 */
final readonly class Authentication
{
    public function __construct(
        public User $user,
        public string $token,
    ) {}
}
