<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->string('kind', 120);
            $table->string('severity', 24);
            $table->string('title', 255);
            $table->text('message');
            $table->uuid('alert_occurrence_id')->nullable();
            $table->string('subject_type', 60)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('action_route', 100)->nullable();
            $table->json('action_parameters')->nullable();
            $table->json('parameters')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['organization_id', 'user_id'])->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
            $table->index(['organization_id', 'user_id', 'created_at', 'id'], 'notification_recipient_created_idx');
            $table->index(['organization_id', 'user_id', 'read_at', 'archived_at'], 'notification_recipient_unread_idx');
        });
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->boolean('optional_email_enabled')->default(true);
            $table->string('locale', 12)->nullable();
            $table->string('digest_frequency', 12)->default('none');
            $table->string('quiet_start', 5)->nullable();
            $table->string('quiet_end', 5)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'user_id']);
            $table->foreign(['organization_id', 'user_id'])->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_records');
    }
};
