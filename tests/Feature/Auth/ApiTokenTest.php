<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Nelson',
            'email' => 'nelson@example.test',
            'password' => 'a-password-nobody-guesses',
        ]);
    }

    /**
     * The plain text value is returned once and never stored. What lands in the
     * table is its hash, so a stolen dump authenticates nobody.
     */
    public function test_the_plain_token_is_returned_but_never_stored(): void
    {
        $user = $this->user();

        $plainText = ApiToken::issueFor($user);

        $this->assertSame(40, strlen($plainText));
        $this->assertDatabaseMissing('api_tokens', ['token' => $plainText]);
        $this->assertDatabaseHas('api_tokens', [
            'user_id' => $user->id,
            'token' => hash('sha256', $plainText),
        ]);
    }

    public function test_two_tokens_issued_in_a_row_are_different(): void
    {
        $user = $this->user();

        $this->assertNotSame(ApiToken::issueFor($user), ApiToken::issueFor($user));
        $this->assertSame(2, $user->apiTokens()->count());
    }

    /**
     * The reason the table exists instead of a column on users: one session per
     * device, not one per person.
     */
    public function test_a_user_may_hold_several_tokens_at_once(): void
    {
        $user = $this->user();

        ApiToken::issueFor($user);
        ApiToken::issueFor($user);
        ApiToken::issueFor($user);

        $this->assertCount(3, $user->fresh()->apiTokens);
    }

    public function test_hashing_is_the_same_function_wherever_it_is_called(): void
    {
        $this->assertSame(hash('sha256', 'a-token'), ApiToken::hash('a-token'));
        $this->assertSame(64, strlen(ApiToken::hash('a-token')));
    }

    public function test_two_tokens_cannot_share_a_hash(): void
    {
        $user = $this->user();

        $user->apiTokens()->create(['token' => ApiToken::hash('same'), 'expires_at' => now()->addDay()]);

        $this->expectException(QueryException::class);

        $user->apiTokens()->create(['token' => ApiToken::hash('same'), 'expires_at' => now()->addDay()]);
    }

    public function test_a_token_expires_after_the_configured_lifetime(): void
    {
        config()->set('auth.api_token_lifetime_hours', 24);

        ApiToken::issueFor($this->user());

        $token = ApiToken::sole();

        $this->assertFalse($token->hasExpired());

        $this->travel(25)->hours();

        $this->assertTrue($token->fresh()->hasExpired());
    }

    public function test_the_lifetime_comes_from_configuration(): void
    {
        config()->set('auth.api_token_lifetime_hours', 2);

        ApiToken::issueFor($this->user());

        $token = ApiToken::sole();

        $this->assertSame(2, (int) round(now()->diffInHours($token->expires_at, absolute: true)));
    }

    /**
     * A read of the API should not cost a write. The mark is only refreshed
     * once it has gone stale.
     */
    public function test_last_used_is_not_rewritten_on_every_use(): void
    {
        ApiToken::issueFor($this->user());
        $token = ApiToken::sole();

        $this->assertNull($token->last_used_at);

        $token->markUsed();
        $firstMark = $token->fresh()->last_used_at;

        $this->assertNotNull($firstMark);

        $this->travel(1)->minutes();
        $token->markUsed();

        $this->assertEquals($firstMark, $token->fresh()->last_used_at, 'A recent mark should be left alone.');

        $this->travel(10)->minutes();
        $token->markUsed();

        $this->assertNotEquals($firstMark, $token->fresh()->last_used_at, 'A stale mark should be refreshed.');
    }

    public function test_deleting_a_user_takes_their_tokens_with_them(): void
    {
        $user = $this->user();
        ApiToken::issueFor($user);

        $user->delete();

        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_the_table_records_when_a_token_was_issued_and_nothing_more(): void
    {
        $this->assertTrue(Schema::hasColumn('api_tokens', 'created_at'));
        $this->assertFalse(Schema::hasColumn('api_tokens', 'updated_at'));
    }
}
