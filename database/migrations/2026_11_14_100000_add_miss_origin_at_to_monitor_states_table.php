<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_states', function (Blueprint $table): void {
            // Where the misses of the recorded push deadline count from, when an outage moved the next one.
            $table->timestamp('miss_origin_at')->nullable()->after('miss_number');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_states', function (Blueprint $table): void {
            $table->dropColumn('miss_origin_at');
        });
    }
};
