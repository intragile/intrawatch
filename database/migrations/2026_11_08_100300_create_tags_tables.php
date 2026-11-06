<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 7);
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('monitor_tag', function (Blueprint $table): void {
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('value', 120)->nullable();

            $table->unique(['monitor_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_tag');
        Schema::dropIfExists('tags');
    }
};
