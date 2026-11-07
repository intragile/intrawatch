<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title', 190);
            $table->string('impact', 16);
            $table->string('status', 16);
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'started_at']);
            $table->index(['workspace_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
