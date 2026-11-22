<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('alert_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('vehicle_id');
            $table->string('rule_key', 100);
            $table->unsignedInteger('rule_version');
            $table->string('episode_key', 100);
            $table->string('threshold_key', 100);
            $table->string('kind', 120);
            $table->string('severity', 24);
            $table->string('state', 24)->default('open');
            $table->string('deduplication_hash', 64);
            $table->timestampTz('occurred_at');
            $table->timestampTz('detected_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'alert_occurrence_tenant_id_unique');
            $table->unique(['organization_id', 'deduplication_hash'], 'alert_occurrence_tenant_key_unique');
            $table->foreign(['organization_id', 'vehicle_id'])->references(['organization_id', 'id'])->on('vehicles')->restrictOnDelete();
        });

        // Null occurrence IDs remain available for non-alert notifications.
        Schema::table('notification_records', function (Blueprint $table) {
            $table->unique(['organization_id', 'alert_occurrence_id', 'user_id', 'kind'], 'notification_occurrence_recipient_unique');
            $table->foreign(['organization_id', 'alert_occurrence_id'], 'notification_occurrence_tenant_fk')
                ->references(['organization_id', 'id'])->on('alert_occurrences')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notification_records', function (Blueprint $table) {
            $table->dropForeign('notification_occurrence_tenant_fk');
            $table->dropUnique('notification_occurrence_recipient_unique');
        });
        Schema::dropIfExists('alert_occurrences');
    }
};
