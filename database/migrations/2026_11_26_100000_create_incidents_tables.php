<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('vehicle_id');
            $table->uuid('driver_id')->nullable();
            $table->uuid('driver_shift_id')->nullable();
            $table->uuid('alert_occurrence_id')->nullable();

            $table->string('source', 32); // telemetry_auto, driver_report, manager_manual
            $table->string('category', 40); // accident, near_miss, critical_dtc, extreme_deceleration, connectivity_loss, other
            $table->string('severity', 20); // low, medium, high, critical
            $table->string('status', 24)->default('open'); // open, investigating, action_required, resolved, closed

            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('detected_at');

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('speed_kmh', 8, 2)->nullable();
            $table->decimal('accel_mps2', 8, 3)->nullable();
            $table->json('dtc_codes')->nullable();
            $table->json('telemetry_context')->nullable();
            $table->string('deduplication_key', 120)->nullable();

            $table->uuid('reported_by_user_id')->nullable();
            $table->uuid('assigned_to_user_id')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->uuid('resolved_by_user_id')->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(['organization_id', 'vehicle_id'])->references(['organization_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign('driver_id')->references('id')->on('drivers')->nullOnDelete();

            $table->unique(['organization_id', 'deduplication_key'], 'uniq_incidents_org_dedup');
            $table->index(['organization_id', 'status', 'occurred_at'], 'idx_incidents_org_status_occurred');
            $table->index(['organization_id', 'vehicle_id', 'occurred_at'], 'idx_incidents_org_vehicle_occurred');
            $table->index(['organization_id', 'driver_id', 'occurred_at'], 'idx_incidents_org_driver_occurred');
        });

        Schema::create('incident_updates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('incident_id');
            $table->uuid('author_user_id')->nullable();
            $table->string('update_type', 32); // note, status_change, assignment, dispatch, insurance_claim, resolution, system_alert
            $table->text('body');
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('incident_id')->references('id')->on('incidents')->cascadeOnDelete();
            $table->index(['organization_id', 'incident_id', 'created_at'], 'idx_incident_updates_org_inc_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_updates');
        Schema::dropIfExists('incidents');
    }
};
