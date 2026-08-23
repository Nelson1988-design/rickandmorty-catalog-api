<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Infrastructure\Auth\ApiTokenGuard;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiTokenGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Routes defined here rather than taken from the application's own, so
        // the guard is tested on its own terms and not through whatever the API
        // happens to expose today.
        Route::middleware('auth:api')->get('/_guard/me', fn (Request $request) => [
            'id' => $request->user()->id,
            'email' => $request->user()->email,
        ]);

        Route::middleware('auth:api')->get('/_guard/token', fn () => [
            'token_id' => Auth::guard('api')->token()?->id,
        ]);
    }

    private function user(string $email = 'nelson@example.test'): User
    {
        return User::create([
            'name' => 'Nelson',
            'email' => $email,
            'password' => 'a-password-nobody-guesses',
        ]);
    }

    public function test_a_request_without_a_token_is_rejected(): void
    {
        $this->getJson('/_guard/me')->assertUnauthorized();
    }

    public function test_a_request_with_an_unknown_token_is_rejected(): void
    {
        $this->withToken('a-token-that-was-never-issued')
            ->getJson('/_guard/me')
            ->assertUnauthorized();
    }

    /**
     * The native integration, stated as an assertion: the controller only calls
     * `$request->user()` and has no idea the authentication is hand written.
     */
    public function test_a_valid_token_resolves_the_user_through_laravel_itself(): void
    {
        $user = $this->user();
        $plainText = ApiToken::issueFor($user);

        $this->withToken($plainText)
            ->getJson('/_guard/me')
            ->assertOk()
            ->assertJson(['id' => $user->id, 'email' => 'nelson@example.test']);
    }

    public function test_an_expired_token_stops_working_without_its_row_being_touched(): void
    {
        $plainText = ApiToken::issueFor($this->user());

        $this->travel(25)->hours();

        $this->withToken($plainText)->getJson('/_guard/me')->assertUnauthorized();

        // Expiry is enforced when the token is read. Deleting the row would be
        // housekeeping, and this test is what says so out loud.
        $this->assertDatabaseCount('api_tokens', 1);
    }

    /**
     * What makes logging out of one device possible.
     */
    public function test_the_guard_exposes_the_token_the_request_arrived_on(): void
    {
        $user = $this->user();
        ApiToken::issueFor($user);
        $second = ApiToken::issueFor($user);

        $expected = ApiToken::where('token', ApiToken::hash($second))->sole();

        $this->withToken($second)
            ->getJson('/_guard/token')
            ->assertOk()
            ->assertJson(['token_id' => $expected->id]);
    }

    /**
     * Laravel caches guard instances and never forgets them within a process,
     * so a guard that remembered a user without remembering whose request it
     * belonged to would hand that user to the next caller.
     */
    public function test_a_resolved_user_does_not_leak_into_the_next_request(): void
    {
        $nelson = $this->user('nelson@example.test');
        $morty = $this->user('morty@example.test');

        $nelsonsToken = ApiToken::issueFor($nelson);
        $mortysToken = ApiToken::issueFor($morty);

        // The second request is the one that matters: if the guard kept the
        // user it resolved the first time, Morty's token would answer with
        // Nelson.
        $this->withToken($nelsonsToken)->getJson('/_guard/me')->assertJson(['email' => 'nelson@example.test']);
        $this->withToken($mortysToken)->getJson('/_guard/me')->assertJson(['email' => 'morty@example.test']);

        // And a request carrying nothing must not inherit either of them.
        // `withToken` writes a default header that survives into the next call,
        // so it has to be cleared for this to mean anything.
        $this->flushHeaders()->getJson('/_guard/me')->assertUnauthorized();
    }

    public function test_using_a_token_records_that_it_was_used(): void
    {
        $plainText = ApiToken::issueFor($this->user());

        $this->assertNull(ApiToken::sole()->last_used_at);

        $this->withToken($plainText)->getJson('/_guard/me')->assertOk();

        $this->assertNotNull(ApiToken::sole()->last_used_at);
    }

    public function test_the_guard_answers_the_contract_without_credentials(): void
    {
        $guard = $this->app->make(ApiTokenGuard::class);

        $this->assertFalse($guard->validate(['email' => 'nelson@example.test', 'password' => 'whatever']));
        $this->assertFalse($guard->hasUser(), 'Asking whether a user is resolved must not resolve one.');
        $this->assertTrue($guard->guest());
    }

    public function test_the_api_guard_is_the_one_we_registered(): void
    {
        $this->assertInstanceOf(ApiTokenGuard::class, Auth::guard('api'));
    }
}
