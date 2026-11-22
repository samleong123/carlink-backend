<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('journeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('vehicle_id');
            $table->string('state', 20);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('last_observed_at');
            $table->uuid('start_observation_id');
            $table->uuid('end_observation_id')->nullable();
            $table->string('end_reason', 48)->nullable();
            $table->unsignedInteger('unknown_seconds')->default(0);
            $table->string('detection_version', 32)->default('pilot-v1');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->foreign(['organization_id', 'vehicle_id'])->references(['organization_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'vehicle_id', 'started_at']);
        });
        Schema::create('journey_detection_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('vehicle_id');
            $table->string('state', 24)->default('stationary');
            $table->uuid('current_journey_id')->nullable();
            $table->uuid('last_observation_id')->nullable();
            $table->timestampTz('last_observed_at')->nullable();
            $table->timestampTz('rebuild_before')->nullable();
            $table->uuid('last_device_id')->nullable();
            $table->uuid('last_installation_id')->nullable();
            $table->uuid('last_boot_id')->nullable();
            $table->unsignedBigInteger('last_uptime_ms')->nullable();
            $table->timestampTz('candidate_started_at')->nullable();
            $table->uuid('candidate_observation_id')->nullable();
            $table->string('stop_mode', 16)->nullable();
            $table->timestampsTz();
            $table->foreign(['organization_id', 'vehicle_id'])->references(['organization_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['organization_id', 'current_journey_id'])->references(['organization_id', 'id'])->on('journeys')->restrictOnDelete();
            $table->unique(['organization_id', 'vehicle_id']);
        });
        Schema::create('journey_processed_observations', function (Blueprint $table) {
            $table->uuid('observation_id')->primary();
            $table->uuid('organization_id');
            $table->uuid('vehicle_id');
            $table->string('outcome', 32);
            $table->timestampTz('processed_at');
            $table->foreign('observation_id')->references('id')->on('mileage_speed_observations')->restrictOnDelete();
            $table->foreign(['organization_id', 'vehicle_id'])->references(['organization_id', 'id'])->on('vehicles')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('journey_processed_observations');
        Schema::dropIfExists('journey_detection_states');
        Schema::dropIfExists('journeys');
    }
};
