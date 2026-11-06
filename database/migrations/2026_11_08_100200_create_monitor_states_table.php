<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_states', function (Blueprint $table): void {
            $table->foreignId('monitor_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->timestamp('status_since');
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('last_latency_ms')->nullable();
            $table->string('last_message', 500)->nullable();
            $table->json('watch')->nullable();
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamp('run_started_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_states');
    }
};
