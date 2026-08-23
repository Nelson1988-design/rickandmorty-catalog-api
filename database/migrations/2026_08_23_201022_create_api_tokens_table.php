<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The sha256 of the token that was handed to the client, never the
            // token itself: whoever reaches this table finds nothing they can
            // authenticate with. 64 characters because that is exactly what a
            // sha256 in hexadecimal measures, and unique because this column is
            // read on every authenticated request — without the index each one
            // would scan the table.
            $table->string('token', 64)->unique();

            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at');

            // Only created_at. An updated_at would be written at exactly the
            // moments last_used_at is, saying the same thing under a worse name.
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
