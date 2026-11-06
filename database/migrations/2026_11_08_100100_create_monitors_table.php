<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('type', 16);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('interval_seconds');
            $table->unsignedInteger('timeout_seconds');
            $table->unsignedSmallInteger('retries');
            $table->unsignedInteger('retry_interval_seconds');
            $table->unsignedInteger('degraded_threshold_ms')->nullable();
            $table->boolean('inverted')->default(false);
            $table->json('config');
            $table->text('secrets')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('monitors')->nullOnDelete();
            $table->string('push_token', 48)->nullable()->unique();
            $table->timestamp('check_requested_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'name']);
            $table->index(['workspace_id', 'type']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitors');
    }
};
