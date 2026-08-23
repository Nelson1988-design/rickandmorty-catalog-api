<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'nelson@example.test', string $password = 'a-password-nobody-guesses'): User
    {
        return User::create(['name' => 'Nelson', 'email' => $email, 'password' => $password]);
    }

    public function test_registering_creates_the_user_and_hands_back_a_token(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Nelson',
            'email' => 'nelson@example.test',
            'password' => 'a-password-nobody-guesses',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.email', 'nelson@example.test');

        $this->assertDatabaseHas('users', ['email' => 'nelson@example.test']);
        $this->assertDatabaseCount('api_tokens', 1);
    }

    public function test_the_registered_password_is_never_stored_as_typed(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Nelson',
            'email' => 'nelson@example.test',
            'password' => 'a-password-nobody-guesses',
        ])->assertCreated();

        $user = User::sole();

        $this->assertNotSame('a-password-nobody-guesses', $user->password);
        $this->assertTrue(Hash::check('a-password-nobody-guesses', $user->password));
    }

    public function test_the_token_it_returns_works_immediately(): void
    {
        $token = $this->postJson('/api/v1/register', [
            'name' => 'Nelson',
            'email' => 'nelson@example.test',
            'password' => 'a-password-nobody-guesses',
        ])->json('token');

        $this->withToken($token)->postJson('/api/v1/logout')->assertNoContent();
    }

    public function test_an_address_cannot_be_registered_twice(): void
    {
        $this->user();

        $this->postJson('/api/v1/register', [
            'name' => 'Someone else',
            'email' => 'nelson@example.test',
            'password' => 'another-password-entirely',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('email');
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Nelson',
            'email' => 'nelson@example.test',
            'password' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_signing_in_returns_a_token(): void
    {
        $this->user();

        $this->postJson('/api/v1/login', [
            'email' => 'nelson@example.test',
            'password' => 'a-password-nobody-guesses',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->assertDatabaseCount('api_tokens', 1);
    }

    /**
     * The same answer whether the address is unknown or the password is wrong.
     * Anything else turns this endpoint into a way of finding out who has an
     * account here.
     */
    public function test_wrong_credentials_and_an_unknown_address_answer_identically(): void
    {
        $this->user();

        $wrongPassword = $this->postJson('/api/v1/login', [
            'email' => 'nelson@example.test',
            'password' => 'not-the-right-one',
        ]);

        $unknownAddress = $this->postJson('/api/v1/login', [
            'email' => 'nobody@example.test',
            'password' => 'not-the-right-one',
        ]);

        $wrongPassword->assertStatus(422)->assertJsonValidationErrors('email');
        $unknownAddress->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame($wrongPassword->json(), $unknownAddress->json());
        $this->assertDatabaseCount('api_tokens', 0);
    }

    /**
     * A second sign-in must not cost the first its session.
     */
    public function test_signing_in_twice_leaves_both_tokens_working(): void
    {
        $this->user();

        $credentials = ['email' => 'nelson@example.test', 'password' => 'a-password-nobody-guesses'];

        $first = $this->postJson('/api/v1/login', $credentials)->json('token');
        $second = $this->postJson('/api/v1/login', $credentials)->json('token');

        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('api_tokens', 2);

        $this->withToken($first)->postJson('/api/v1/logout')->assertNoContent();
        $this->withToken($second)->postJson('/api/v1/logout')->assertNoContent();
    }

    /**
     * The decision that needed the guard to expose its token: logging out of
     * one device leaves the others signed in.
     */
    public function test_logging_out_revokes_only_the_token_that_was_used(): void
    {
        $user = $this->user();

        $phone = ApiToken::issueFor($user);
        $laptop = ApiToken::issueFor($user);

        $this->withToken($phone)->postJson('/api/v1/logout')->assertNoContent();

        $this->assertDatabaseCount('api_tokens', 1);

        $this->flushHeaders()->withToken($phone)->getJson('/api/v1/logout')->assertStatus(405);
        $this->flushHeaders()->withToken($phone)->postJson('/api/v1/logout')->assertUnauthorized();
        $this->flushHeaders()->withToken($laptop)->postJson('/api/v1/logout')->assertNoContent();
    }

    public function test_logging_out_needs_a_token(): void
    {
        $this->postJson('/api/v1/logout')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    /**
     * An authentication endpoint without a ceiling is an invitation to guess.
     */
    public function test_repeated_sign_in_attempts_are_throttled(): void
    {
        $this->user();

        $wrong = ['email' => 'nelson@example.test', 'password' => 'not-the-right-one'];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/login', $wrong)->assertStatus(422);
        }

        $this->postJson('/api/v1/login', $wrong)
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    }

    public function test_the_api_lives_under_a_version(): void
    {
        $this->postJson('/api/register', ['name' => 'Nelson'])->assertNotFound();
    }
}
