<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_episode', function (Blueprint $table) {
            // Real foreign keys, on purpose. Synchronisation runs locations,
            // then episodes, then characters, so a violation here cannot mean
            // "this one character failed" — it can only mean the episode pass
            // before it finished incomplete. That is worth stopping for, and
            // far better than a pivot row pointing nowhere that nobody notices.
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('episode_id')->constrained()->cascadeOnDelete();

            // The pair is the identity, so it is the primary key: no surrogate
            // id nobody would ever use, and the uniqueness the synchronisation
            // relies on is enforced by the database rather than merely assumed.
            $table->primary(['character_id', 'episode_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_episode');
    }
};
