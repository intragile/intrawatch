<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('monitor_id');
            $table->string('kind', 32);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->string('key')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('message', 500);
            $table->dateTime('created_at');
            $table->index(['monitor_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_events');
    }
};
