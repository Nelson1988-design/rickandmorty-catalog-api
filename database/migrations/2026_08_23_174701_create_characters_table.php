<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('external_id')->unique();

            $table->string('name');

            // Not nullable, because the mapper guarantees a value: an
            // unrecognised or absent status becomes the Unknown case, never
            // null. The constraint enforces a promise the domain already makes,
            // so a null arriving here means the mapper broke its contract and
            // the write should fail loudly.
            $table->string('status')->index();
            $table->string('gender');

            // Nullable, because the mapper is allowed to produce null for
            // these: half the catalogue carries an empty type. A stricter
            // column than the contract that feeds it would turn a documented
            // degradation into an integrity error.
            $table->string('species')->nullable()->index();
            $table->string('type')->nullable();
            $table->string('image')->nullable();

            // Two references to the same table: where the character comes from
            // and where it is now. Both nullable because the provider reports
            // an unknown place with an empty URL — 300 characters have no
            // origin and 21 no current location, better than a third of the
            // catalogue.
            $table->foreignId('origin_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('current_location_id')->nullable()->constrained('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
