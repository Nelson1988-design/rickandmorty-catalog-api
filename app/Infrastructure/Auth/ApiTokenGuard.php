<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Models\ApiToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * Authenticates a request by the bearer token it carries.
 *
 * It implements Laravel's own Guard contract rather than living as a middleware
 * of its own, and that is the whole point: once it is registered as a driver,
 * nothing else in the application knows the authentication is hand written.
 * `auth:api`, `$request->user()`, form requests and policies all keep working
 * without a line of adaptation. A middleware that stuffed a user into the
 * container would look equivalent and fall apart the first time someone wrote
 * a policy.
 */
final class ApiTokenGuard implements Guard
{
    private ?Authenticatable $user = null;

    private ?ApiToken $token = null;

    /**
     * The request the current answer belongs to.
     *
     * Laravel caches guard instances — `AuthManager::guard()` keeps them in a
     * property — and never forgets them between requests in a single process.
     * A guard that remembered its user without remembering *whose request* it
     * came from would hand that user to the next caller, which under a resident
     * runtime is a stranger. Resolution is therefore keyed on the request
     * itself, not on a boolean.
     */
    private ?Request $resolvedFor = null;

    public function __construct(private readonly Container $container) {}

    public function user(): ?Authenticatable
    {
        $request = $this->request();

        if ($this->resolvedFor === $request) {
            return $this->user;
        }

        $this->resolvedFor = $request;
        $this->user = null;
        $this->token = null;

        $plainText = $request->bearerToken();

        if ($plainText === null || $plainText === '') {
            return null;
        }

        $token = ApiToken::with('user')->firstWhere('token', ApiToken::hash($plainText));

        // An expired token is simply not a token. Its row is left alone:
        // expiry is enforced when it is read, and tidying the table afterwards
        // would be housekeeping, not security.
        if ($token === null || $token->hasExpired()) {
            return null;
        }

        $token->markUsed();

        $this->token = $token;

        return $this->user = $token->user;
    }

    /**
     * The token this request came in on.
     *
     * Logging out has to revoke the token that was used and only that one —
     * a user may well be signed in on other devices — and `$request->user()`
     * cannot say which one it was. The guard found it, so the guard keeps it.
     */
    public function token(): ?ApiToken
    {
        $this->user();

        return $this->token;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * Whether a user has already been resolved, without resolving one.
     */
    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): void
    {
        $this->user = $user;
        $this->resolvedFor = $this->request();
    }

    /**
     * Meaningless for a token guard: there are no credentials to check, only a
     * token that either resolves or does not. Answering false is the honest
     * reply — an empty body would read like an oversight.
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    private function request(): Request
    {
        return $this->container->make('request');
    }
}
