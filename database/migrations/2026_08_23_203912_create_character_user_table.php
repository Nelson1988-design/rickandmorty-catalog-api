<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();

            // Filled by the database rather than by the application, which is
            // what lets adding a favourite be a single idempotent statement: the
            // relationship can be attached without carrying any attributes, so
            // attaching one that is already there has nothing to overwrite.
            $table->dateTime('created_at')->useCurrent();

            // The pair is the identity. Same reasoning as character_episode, and
            // here it doubles as the guarantee that a favourite cannot be stored
            // twice even if two requests arrive at the same instant.
            $table->primary(['user_id', 'character_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_user');
    }
};
