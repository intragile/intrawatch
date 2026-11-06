<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No foreign key: results of deleted monitors are removed by the purge job, not by cascades that
        // would lock the hot table during a delete.
        Schema::create('check_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('monitor_id')->index();
            $table->string('status', 16);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('message', 500)->nullable();
            $table->json('facts')->nullable();
            $table->dateTime('checked_at');
            $table->integer('drift_ms')->nullable();
            $table->bigInteger('bucket_minute');
            $table->index(['monitor_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_results');
    }
};
