<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_states', function (Blueprint $table): void {
            // The moment the current push or cron deadline counts from: the last heartbeat or completed run, or
            // the moment the monitor was created, resumed or had its timing edited.
            $table->timestamp('deadline_anchor_at')->nullable()->after('run_started_at');
        });

        DB::table('monitor_states')->update(['deadline_anchor_at' => DB::raw('coalesce(last_ping_at, status_since)')]);
    }

    public function down(): void
    {
        Schema::table('monitor_states', function (Blueprint $table): void {
            $table->dropColumn('deadline_anchor_at');
        });
    }
};
