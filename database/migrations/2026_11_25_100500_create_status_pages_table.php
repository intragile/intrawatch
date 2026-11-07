<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 64)->unique();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('custom_domain', 253)->nullable()->unique();
            $table->boolean('published')->default(false);
            $table->string('locale', 8)->default('en');
            $table->string('accent_color', 7)->nullable();
            $table->string('logo_url', 2048)->nullable();
            $table->string('favicon_url', 2048)->nullable();
            $table->text('custom_css')->nullable();
            $table->text('footer')->nullable();
            $table->unsignedSmallInteger('bar_days')->default(90);
            $table->unsignedSmallInteger('uptime_days')->default(30);
            $table->unsignedSmallInteger('upcoming_maintenance_days')->default(7);
            $table->unsignedSmallInteger('incident_history_days')->default(14);
            $table->boolean('show_latency')->default(true);
            $table->timestamps();
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_pages');
    }
};
