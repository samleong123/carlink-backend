<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('telemetry_inbox', function (Blueprint $t) {
            $t->uuid('boot_id')->nullable()->change();
            $t->unsignedBigInteger('sequence')->nullable()->change();
            $t->string('source_format', 24)->default('canonical_v1');
        });
        Schema::table('mileage_speed_observations', function (Blueprint $t) {
            $t->uuid('boot_id')->nullable()->change();
            $t->unsignedBigInteger('observed_uptime_ms')->nullable()->change();
            $t->string('source_kind', 24)->default('obd_sample');
            $t->unique('inbox_id');
        });
        Schema::table('telemetry_samples', function (Blueprint $t) {
            $t->string('source_format', 24)->default('canonical_v1');
            $t->json('vehicle_data')->nullable(); $t->json('network_data')->nullable();
            $t->json('health_data')->nullable(); $t->json('behaviour_data')->nullable();
            $t->uuid('authorized_shift_id')->nullable();
            $t->decimal('fuel_rate_lph', 12, 6)->nullable(); $t->string('fuel_rate_source', 32)->nullable();
        });
        Schema::create('vehicle_energy_segments', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('vehicle_id');
            $t->uuid('start_sample_id'); $t->uuid('end_sample_id');
            $t->timestampTz('from_at', 6); $t->timestampTz('to_at', 6);
            $t->decimal('litres', 16, 9)->nullable(); $t->string('source', 32); $t->string('quality', 48);
            $t->timestampTz('superseded_at')->nullable(); $t->timestampTz('created_at');
            $t->foreign(['organization_id','vehicle_id'])->references(['organization_id','id'])->on('vehicles')->restrictOnDelete();
            $t->foreign('start_sample_id')->references('id')->on('telemetry_samples')->restrictOnDelete();
            $t->foreign('end_sample_id')->references('id')->on('telemetry_samples')->restrictOnDelete();
            $t->index(['organization_id','vehicle_id','from_at']);
        });
    }

    public function down(): void
    {
        // Preserve accepted Pi records: restoring NOT NULL would discard their honest unknown metadata.
        throw new \LogicException('This data-preserving migration requires a reviewed forward rollback plan.');
    }
};
