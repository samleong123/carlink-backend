<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('telemetry_inbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('device_id');
            $table->uuid('installation_id');
            $table->uuid('event_id');
            $table->uuid('boot_id');
            $table->unsignedBigInteger('sequence');
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->string('payload_sha256', 64);
            $table->text('raw_payload');
            $table->string('state', 32)->default('pending_projection');
            $table->foreign(['organization_id', 'device_id'])->references(['organization_id', 'id'])->on('devices')->restrictOnDelete();
            $table->unique(['device_id', 'event_id']);
            $table->unique(['device_id', 'boot_id', 'sequence']);
            $table->index(['organization_id', 'occurred_at']);
        });
        Schema::create('telemetry_quarantine', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('route_sha256', 64);
            $table->string('payload_sha256', 64);
            $table->string('reason', 48);
            $table->timestampTz('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_quarantine');
        Schema::dropIfExists('telemetry_inbox');
    }
};
