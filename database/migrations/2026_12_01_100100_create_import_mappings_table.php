<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);
            $table->char('instance_key', 64);
            $table->string('entity', 32);
            $table->bigInteger('source_id');
            $table->string('target_type', 64);
            $table->bigInteger('target_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['workspace_id', 'source', 'instance_key', 'entity', 'source_id'], 'import_mappings_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
    }
};
