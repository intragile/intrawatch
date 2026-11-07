<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->unsignedInteger('resend_interval_minutes')->default(0);
            $table->boolean('notify_degraded')->default(true);
            $table->unsignedInteger('degraded_alert_after_seconds')->default(300);
            $table->boolean('notify_changes')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['resend_interval_minutes', 'notify_degraded', 'degraded_alert_after_seconds', 'notify_changes']);
        });
    }
};
