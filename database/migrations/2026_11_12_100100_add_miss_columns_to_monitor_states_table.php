<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_states', function (Blueprint $table): void {
            // The last missed push heartbeat recorded: its number, counted from the deadline it belongs to.
            $table->timestamp('miss_deadline_at')->nullable()->after('deadline_anchor_at');
            $table->unsignedInteger('miss_number')->nullable()->after('miss_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_states', function (Blueprint $table): void {
            $table->dropColumn(['miss_deadline_at', 'miss_number']);
        });
    }
};
