<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Catalog\Enums\CharacterGender;
use App\Domain\Catalog\Enums\CharacterStatus;
use App\Models\ApiToken;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FavoritesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Nelson',
            'email' => 'nelson@example.test',
            'password' => 'a-password-nobody-guesses',
        ]);

        $this->withToken(ApiToken::issueFor($this->user));
    }

    private function character(int $externalId, string $name): Character
    {
        return Character::create([
            'external_id' => $externalId,
            'name' => $name,
            'status' => CharacterStatus::Alive,
            'gender' => CharacterGender::Male,
            'species' => 'Human',
            'image' => 'https://rickandmortyapi.com/api/character/avatar/'.$externalId.'.jpeg',
        ]);
    }

    public function test_a_character_can_be_marked_and_then_appears_in_the_list(): void
    {
        $rick = $this->character(1, 'Rick Sanchez');

        $this->postJson("/api/v1/favorites/{$rick->id}")->assertNoContent();

        $this->getJson('/api/v1/favorites')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Rick Sanchez')
            ->assertJsonPath('data.0.external_id', 1);
    }

    /**
     * A retry after a dropped connection asks for the same state, not for a
     * second row.
     */
    public function test_marking_the_same_character_twice_changes_nothing(): void
    {
        $rick = $this->character(1, 'Rick Sanchez');

        $this->postJson("/api/v1/favorites/{$rick->id}")->assertNoContent();
        $afterFirst = DB::table('character_user')->get()->toJson();

        $this->postJson("/api/v1/favorites/{$rick->id}")->assertNoContent();

        $this->assertDatabaseCount('character_user', 1);
        $this->assertSame($afterFirst, DB::table('character_user')->get()->toJson());
    }

    public function test_removing_a_favourite_takes_it_off_the_list(): void
    {
        $rick = $this->character(1, 'Rick Sanchez');

        $this->postJson("/api/v1/favorites/{$rick->id}")->assertNoContent();
        $this->deleteJson("/api/v1/favorites/{$rick->id}")->assertNoContent();

        $this->getJson('/api/v1/favorites')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * The caller asked for a state, and that state holds whether or not
     * anything had to change.
     */
    public function test_removing_something_that_was_never_a_favourite_is_not_an_error(): void
    {
        $rick = $this->character(1, 'Rick Sanchez');

        $this->deleteJson("/api/v1/favorites/{$rick->id}")->assertNoContent();
        $this->deleteJson("/api/v1/favorites/{$rick->id}")->assertNoContent();

        $this->assertDatabaseCount('character_user', 0);
    }

    public function test_favourites_are_listed_with_the_most_recent_first(): void
    {
        $rick = $this->character(1, 'Rick Sanchez');
        $morty = $this->character(2, 'Morty Smith');

        $this->postJson("/api/v1/favorites/{$rick->id}")->assertNoContent();
        $this->travel(1)->minutes();
        $this->postJson("/api/v1/favorites/{$morty->id}")->assertNoContent();

        $this->getJson('/api/v1/favorites')
            ->assertJsonPath('data.0.name', 'Morty Smith')
            ->assertJsonPath('data.1.name', 'Rick Sanchez');
    }

    public function test_one_users_favourites_are_not_anothers(): void
    {
        $rick = $this->character(1, 'Rick Sanchez');

        $this->postJson("/api/v1/favorites/{$rick->id}")->assertNoContent();

        $someoneElse = User::create([
            'name' => 'Morty',
            'email' => 'morty@example.test',
            'password' => 'another-password-entirely',
        ]);

        $this->flushHeaders()->withToken(ApiToken::issueFor($someoneElse));

        $this->getJson('/api/v1/favorites')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_marking_a_character_that_does_not_exist_answers_not_found(): void
    {
        $this->postJson('/api/v1/favorites/4242')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_the_list_is_paginated(): void
    {
        foreach (range(1, 25) as $externalId) {
            $character = $this->character($externalId, 'Character '.$externalId);
            $this->postJson("/api/v1/favorites/{$character->id}")->assertNoContent();
        }

        $this->getJson('/api/v1/favorites')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonPath('meta.total', 25);
    }

    public function test_every_favourites_endpoint_needs_a_token(): void
    {
        $rick = $this->character(1, 'Rick Sanchez');

        $this->flushHeaders();

        $this->getJson('/api/v1/favorites')->assertUnauthorized();
        $this->postJson("/api/v1/favorites/{$rick->id}")->assertUnauthorized();
        $this->deleteJson("/api/v1/favorites/{$rick->id}")->assertUnauthorized();
    }
}
