<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_notification', function (Blueprint $table): void {
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->primary(['monitor_id', 'notification_channel_id']);
            $table->index('notification_channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_notification');
    }
};
