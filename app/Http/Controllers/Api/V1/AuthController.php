<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Auth\Authentication;
use App\Application\Auth\LoginUserUseCase;
use App\Application\Auth\LogoutUserUseCase;
use App\Application\Auth\RegisterUserUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Infrastructure\Auth\ApiTokenGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * An adapter, not a place where things happen. Each method reads the request,
 * calls a use case and shapes the answer.
 */
final class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUserUseCase $register): JsonResponse
    {
        $authentication = $register->execute(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return $this->answer($authentication, Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request, LoginUserUseCase $login): JsonResponse
    {
        $authentication = $login->execute(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return $this->answer($authentication, Response::HTTP_OK);
    }

    public function logout(LogoutUserUseCase $logout): Response
    {
        /** @var ApiTokenGuard $guard */
        $guard = Auth::guard('api');

        // The guard is asked which token this request came in on. The use case
        // receives it and never learns that guards exist.
        $logout->execute($guard->token());

        return response()->noContent();
    }

    private function answer(Authentication $authentication, int $status): JsonResponse
    {
        return response()->json([
            // The only moment the plain token is ever readable.
            'token' => $authentication->token,
            'user' => new UserResource($authentication->user),
        ], $status);
    }
}
