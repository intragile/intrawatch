<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        Schema::create('imports', function (Blueprint $table) use ($mysql): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32);
            $table->string('status', 16);
            $table->json('options');
            $table->string('file_path')->nullable();
            $table->string('file_name', 255)->nullable();
            $table->bigInteger('file_size')->nullable();
            // A report with thousands of entries outgrows MySQL's 64 KB TEXT-backed JSON limit in practice.
            $mysql ? $table->longText('report')->nullable() : $table->json('report')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
