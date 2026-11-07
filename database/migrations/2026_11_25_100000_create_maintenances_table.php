<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('strategy', 16);
            $table->string('timezone', 64)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->char('start_time', 5)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('every_days')->nullable();
            $table->json('weekdays')->nullable();
            $table->json('month_days')->nullable();
            $table->string('cron', 100)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['workspace_id', 'id']);
            $table->index(['active', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenances');
    }
};
