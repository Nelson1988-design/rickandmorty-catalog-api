<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            // Our own key stays ours. The provider's identifier is stored beside
            // it, unique so a record can be reconciled across runs, but nothing
            // in this database points at it.
            $table->unsignedInteger('external_id')->unique();

            $table->string('name');
            $table->string('type')->nullable();
            $table->string('dimension')->nullable();

            // No timestamps on purpose. This table is a projection of an
            // external catalogue, not an entity with a life of its own here,
            // and `updated_at` would be written on every run or would go stale
            // on the first change. Neither reading is worth the column.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
