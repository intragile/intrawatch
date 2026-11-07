<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 190)->nullable();
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('position');
            $table->boolean('expand_children')->default(false);
            $table->boolean('show_uptime')->default(true);
            $table->unique(['status_page_section_id', 'monitor_id']);
            $table->index('monitor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_items');
    }
};
