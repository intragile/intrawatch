<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Public registration data, shared by every workspace.
        Schema::create('domain_lookups', function (Blueprint $table): void {
            $table->string('domain', 253)->primary();
            $table->string('source', 8);
            $table->string('status', 16);
            // A datetime, not a timestamp: registrations can run past 2038.
            $table->dateTime('expires_at')->nullable();
            $table->string('registrar', 255)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_lookups');
    }
};
