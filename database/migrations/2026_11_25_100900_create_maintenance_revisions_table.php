<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single counter that every maintenance write increments, so the maintenance calendar's change stamp moves
     * even for writes that `updated_at`, with its one-second precision, cannot tell apart.
     */
    public function up(): void
    {
        Schema::create('maintenance_revisions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });
        DB::table('maintenance_revisions')->insert(['id' => 1, 'revision' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_revisions');
    }
};
