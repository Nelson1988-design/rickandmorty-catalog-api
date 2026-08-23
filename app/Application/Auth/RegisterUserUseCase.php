<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\ApiToken;
use App\Models\User;

final class RegisterUserUseCase
{
    public function execute(string $name, string $email, string $password): Authentication
    {
        // The password is hashed by the model's cast, not here: leaving it to
        // the one place that already knows how means there is no second place
        // that could get it wrong.
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        return new Authentication($user, ApiToken::issueFor($user));
    }
}
