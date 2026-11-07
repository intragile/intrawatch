<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->foreignId('monitor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16)->nullable();
            $table->string('previous_status', 16)->nullable();
            $table->string('message', 500);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('changes')->nullable();
            $table->unsignedBigInteger('suppressed_by_monitor_id')->nullable();
            $table->dateTime('created_at');
            $table->index(['workspace_id', 'id']);
            $table->index(['monitor_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
    }
};
