<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->index();
            // We deliberately break Laravel's convention by omitting $table->timestamps().
            // Since this is a synchronization history table, the 'created_at' and
            // 'updated_at' fields are redundant and lack semantic value.
            // Instead, we use 'started_at' and 'finished_at' to accurately represent
            // the lifecycle of the process.
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
