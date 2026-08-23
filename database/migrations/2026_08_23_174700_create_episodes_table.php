<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('external_id')->unique();

            $table->string('name');

            // Indexed but deliberately not unique. The code belongs to the
            // provider, not to us: a two-part special sharing a code, or a
            // renumbered season, would be a legitimate change that a unique
            // constraint would turn into a failed synchronisation. Uniqueness
            // guards what we control, and that is `external_id`.
            $table->string('code')->nullable()->index();

            // A date, not a datetime. The provider gives a day — "December 2,
            // 2013" — so storing a time of 00:00:00 would claim a precision we
            // do not have and invite timezone bugs later.
            $table->date('air_date')->nullable();

            // The original string survives even when parsing does not, so a
            // change in the provider's date format costs the parsed value but
            // never the information.
            $table->string('air_date_raw')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
