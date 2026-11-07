<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_links', function (Blueprint $table): void {
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('linkable_type', 16);
            $table->unsignedBigInteger('linkable_id');
            $table->primary(['status_page_id', 'linkable_type', 'linkable_id']);
            $table->index(['linkable_type', 'linkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_links');
    }
};
