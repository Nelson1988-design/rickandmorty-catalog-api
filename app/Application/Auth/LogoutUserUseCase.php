<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\ApiToken;

final class LogoutUserUseCase
{
    /**
     * Revokes one token: the one the request arrived on.
     *
     * Not all of the user's tokens. Signing out of a phone should not sign the
     * same person out of their laptop, and the whole reason tokens live in
     * their own table is to make that distinction possible.
     */
    public function execute(?ApiToken $token): void
    {
        $token?->delete();
    }
}
