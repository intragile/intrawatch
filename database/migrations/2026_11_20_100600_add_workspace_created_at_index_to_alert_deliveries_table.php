<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_deliveries', function (Blueprint $table): void {
            // The alert history looks up a page's deliveries from the oldest alert of the page onwards.
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('alert_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'created_at']);
        });
    }
};
