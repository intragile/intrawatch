<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('name', 190);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('position');
            $table->index(['status_page_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_sections');
    }
};
