<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class LoginUserUseCase
{
    /**
     * @throws ValidationException
     */
    public function execute(string $email, string $password): Authentication
    {
        $user = User::firstWhere('email', $email);

        // One failure for both causes, deliberately. Answering differently when
        // the address is unknown would turn this endpoint into a way of finding
        // out who is registered.
        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // A fresh token per sign-in rather than reusing the last one: signing in
        // on a second device must not invalidate the first, and revoking one
        // must not touch the others.
        return new Authentication($user, ApiToken::issueFor($user));
    }
}
