<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mileage_speed_observations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('vehicle_id');
            $t->uuid('installation_id'); $t->uuid('device_id'); $t->uuid('boot_id'); $t->uuid('sample_id');
            $t->uuid('inbox_id'); $t->timestampTz('observed_at', 6); $t->unsignedBigInteger('observed_uptime_ms');
            $t->decimal('speed_kmh', 10, 3)->nullable(); $t->string('quality', 24); $t->string('fingerprint', 64);
            $t->timestampTz('created_at', 6); $t->unique(['device_id','boot_id','sample_id']);
            $t->index(['organization_id','vehicle_id','observed_at']);
            $t->foreign(['organization_id','vehicle_id'])->references(['organization_id','id'])->on('vehicles')->restrictOnDelete();
            $t->foreign('installation_id')->references('id')->on('device_installations')->restrictOnDelete();
            $t->foreign('inbox_id')->references('id')->on('telemetry_inbox')->restrictOnDelete();
        });
        Schema::create('vehicle_distance_segments', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('vehicle_id');
            $t->uuid('start_observation_id'); $t->uuid('end_observation_id');
            $t->timestampTz('from_at', 6); $t->timestampTz('to_at', 6); $t->decimal('distance_m', 20, 6)->nullable();
            $t->string('source', 24); $t->string('quality', 40); $t->string('algorithm', 30);
            $t->timestampTz('superseded_at', 6)->nullable(); $t->timestampTz('created_at', 6);
            $t->foreign(['organization_id','vehicle_id'])->references(['organization_id','id'])->on('vehicles')->restrictOnDelete();
            $t->foreign('start_observation_id')->references('id')->on('mileage_speed_observations')->restrictOnDelete();
            $t->foreign('end_observation_id')->references('id')->on('mileage_speed_observations')->restrictOnDelete();
            $t->index(['organization_id','vehicle_id','from_at','to_at']);
        });
        Schema::create('odometer_observations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('vehicle_id'); $t->uuid('created_by');
            $t->timestampTz('observed_at', 6); $t->unsignedBigInteger('reading_m'); $t->string('reason', 500);
            $t->timestampTz('created_at', 6);
            $t->foreign(['organization_id','vehicle_id'])->references(['organization_id','id'])->on('vehicles')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['organization_id','vehicle_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('odometer_observations'); Schema::dropIfExists('vehicle_distance_segments'); Schema::dropIfExists('mileage_speed_observations'); }
};
