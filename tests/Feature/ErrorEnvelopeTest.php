<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Every error answer, whatever produced it, carries the same two keys.
 */
final class ErrorEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_errors/missing-model', fn () => Character::findOrFail(4242));
        Route::get('/_errors/protected', fn () => 'never reached')->middleware('auth:api');
        Route::get('/_errors/boom', fn () => throw new RuntimeException('Something came apart.'));
        Route::post('/_errors/validated', fn (Request $request) => $request->validate([
            'email' => ['required', 'email'],
        ]));
        Route::get('/_errors/limited', fn () => 'ok')->middleware('throttle:1,1');
    }

    public function test_a_missing_record_answers_not_found(): void
    {
        $this->getJson('/_errors/missing-model')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found')
            ->assertJsonStructure(['message', 'code']);
    }

    public function test_an_unknown_route_answers_not_found(): void
    {
        $this->getJson('/_errors/there-is-nothing-here')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_a_request_without_a_token_answers_unauthenticated(): void
    {
        $this->getJson('/_errors/protected')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonStructure(['message', 'code']);
    }

    public function test_the_wrong_method_answers_method_not_allowed(): void
    {
        $this->postJson('/_errors/protected')
            ->assertStatus(405)
            ->assertJsonPath('code', 'method_not_allowed');
    }

    /**
     * Validation is the one case that carries a third key, and it keeps it.
     */
    public function test_a_failed_validation_keeps_its_field_errors(): void
    {
        $this->postJson('/_errors/validated', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['message', 'code', 'errors' => ['email']]);
    }

    public function test_too_many_requests_answers_with_its_own_code(): void
    {
        $this->getJson('/_errors/limited')->assertOk();

        $this->getJson('/_errors/limited')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    }

    public function test_an_unexpected_failure_answers_server_error(): void
    {
        $this->getJson('/_errors/boom')
            ->assertStatus(500)
            ->assertJsonPath('code', 'server_error')
            ->assertJsonStructure(['message', 'code']);
    }

    /**
     * The decorator only touches failures. A successful answer keeps whatever
     * shape its endpoint decided on.
     */
    public function test_a_successful_answer_is_left_alone(): void
    {
        Route::get('/_errors/fine', fn () => ['status' => 'all good']);

        $this->getJson('/_errors/fine')
            ->assertOk()
            ->assertExactJson(['status' => 'all good']);
    }

    public function test_the_two_keys_lead_the_payload(): void
    {
        $body = $this->getJson('/_errors/protected')->json();

        $this->assertSame(['message', 'code'], array_slice(array_keys($body), 0, 2));
    }
}
