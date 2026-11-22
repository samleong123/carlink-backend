<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broker_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('device_id'); $t->string('deduplication_key', 160)->unique();
            $t->string('routing_key', 160); $t->json('payload'); $t->unsignedInteger('generation')->default(1);
            $t->unsignedInteger('attempts')->default(0); $t->timestampTz('available_at'); $t->timestampTz('expires_at');
            $t->uuid('lease_token')->nullable(); $t->timestampTz('locked_until')->nullable(); $t->timestampTz('published_at')->nullable();
            $t->timestampTz('cancelled_at')->nullable(); $t->timestampsTz(); $t->index(['published_at', 'available_at']);
            $t->foreign(['organization_id', 'device_id'])->references(['organization_id', 'id'])->on('devices')->restrictOnDelete();
        });
        Schema::table('telemetry_inbox', function (Blueprint $t) {
            $t->unsignedInteger('projection_attempts')->default(0); $t->timestampTz('projection_available_at')->nullable();
            $t->timestampTz('projected_at')->nullable(); $t->string('projection_error', 64)->nullable(); $t->index(['state', 'projection_available_at']);
            $t->unique(['organization_id', 'id']);
        });
        Schema::create('telemetry_samples', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('vehicle_id'); $t->uuid('installation_id'); $t->uuid('inbox_id')->unique();
            $t->timestampTz('occurred_at', 6); $t->timestampTz('received_at', 6); $t->decimal('speed_kmh', 10, 3)->nullable();
            $t->decimal('engine_rpm', 12, 3)->nullable(); $t->decimal('latitude', 10, 7)->nullable(); $t->decimal('longitude', 10, 7)->nullable();
            $t->timestampTz('position_observed_at', 6)->nullable(); $t->json('quality'); $t->timestampTz('created_at');
            $t->foreign(['organization_id', 'vehicle_id'])->references(['organization_id', 'id'])->on('vehicles')->restrictOnDelete();
            $t->foreign(['organization_id', 'inbox_id'])->references(['organization_id', 'id'])->on('telemetry_inbox')->restrictOnDelete();
            $t->index(['organization_id', 'vehicle_id', 'occurred_at', 'id']);
        });
        Schema::create('vehicle_latest_states', function (Blueprint $t) {
            $t->uuid('organization_id'); $t->uuid('vehicle_id'); $t->uuid('sample_id'); $t->timestampTz('occurred_at', 6); $t->timestampTz('received_at', 6);
            $t->unsignedInteger('version')->default(1); $t->primary(['organization_id', 'vehicle_id']);
            $t->foreign(['organization_id', 'vehicle_id'])->references(['organization_id', 'id'])->on('vehicles')->restrictOnDelete();
            $t->foreign('sample_id')->references('id')->on('telemetry_samples')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_latest_states'); Schema::dropIfExists('telemetry_samples'); Schema::dropIfExists('broker_outbox');
        Schema::table('telemetry_inbox', function (Blueprint $t) {
            $t->dropIndex(['state', 'projection_available_at']); $t->dropUnique(['organization_id', 'id']);
            $t->dropColumn(['projection_attempts', 'projection_available_at', 'projected_at', 'projection_error']);
        });
    }
};
