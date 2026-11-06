<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No foreign key, for the same reason as check_results: the purge job removes the rows of a deleted
        // monitor in short chunks.
        Schema::create('check_rollups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('monitor_id');
            $table->string('bucket', 8);
            $table->bigInteger('bucket_start');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('up')->default(0);
            $table->unsignedInteger('down')->default(0);
            $table->unsignedInteger('degraded')->default(0);
            $table->unsignedInteger('pending')->default(0);
            $table->unsignedInteger('maintenance')->default(0);
            $table->unsignedInteger('latency_min')->nullable();
            $table->unsignedInteger('latency_max')->nullable();
            $table->unsignedInteger('latency_avg')->nullable();
            $table->unsignedBigInteger('latency_sum')->default(0);
            $table->unsignedInteger('latency_count')->default(0);
            $table->unique(['monitor_id', 'bucket', 'bucket_start']);
            // Rollup builds and retention select one bucket level by time across all monitors.
            $table->index(['bucket', 'bucket_start']);
        });

        Schema::table('check_results', function (Blueprint $table): void {
            // Minute rollups aggregate one bucket_minute across all monitors; retention finds its id bounds here.
            $table->index('bucket_minute');
        });

        Schema::table('monitor_events', function (Blueprint $table): void {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_events', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
        Schema::table('check_results', function (Blueprint $table): void {
            $table->dropIndex(['bucket_minute']);
        });
        Schema::dropIfExists('check_rollups');
    }
};
