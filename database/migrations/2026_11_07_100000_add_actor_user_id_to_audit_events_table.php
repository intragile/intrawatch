<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The human behind the action: the user for session actors, the token's owner for token actors.
        // No foreign key, for the same reason as workspace_id: the trail outlives the user.
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('actor_user_id')->nullable()->after('actor_id');
            $table->index(['workspace_id', 'actor_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'actor_user_id']);
            $table->dropColumn('actor_user_id');
        });
    }
};
