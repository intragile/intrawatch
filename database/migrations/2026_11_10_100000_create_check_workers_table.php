<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_workers', function (Blueprint $table): void {
            $table->unsignedInteger('shard')->primary();
            $table->unsignedInteger('shards');
            $table->string('hostname', 255);
            $table->unsignedInteger('pid');
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('monitors')->default(0);
            $table->unsignedInteger('checks_last_minute')->default(0);
            $table->unsignedInteger('max_drift_ms_last_minute')->default(0);
            $table->unsignedInteger('buffered')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_workers');
    }
};
