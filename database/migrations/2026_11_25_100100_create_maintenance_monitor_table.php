<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_monitor', function (Blueprint $table): void {
            $table->foreignId('maintenance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->primary(['maintenance_id', 'monitor_id']);
            $table->index('monitor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_monitor');
    }
};
