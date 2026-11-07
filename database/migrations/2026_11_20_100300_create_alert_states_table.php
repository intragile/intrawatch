<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_states', function (Blueprint $table): void {
            $table->foreignId('monitor_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('notified', 16)->nullable();
            $table->string('observed', 16)->nullable();
            $table->string('current', 16)->nullable();
            $table->timestamp('problem_since')->nullable();
            $table->timestamp('down_since')->nullable();
            $table->timestamp('degraded_since')->nullable();
            $table->boolean('flapping')->default(false);
            $table->timestamp('flapping_since')->nullable();
            $table->json('recent_changes')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedInteger('reminders_sent')->default(0);
            $table->boolean('suppressed')->default(false);
            $table->timestamp('last_signal_at')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_states');
    }
};
