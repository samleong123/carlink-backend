<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('registration_number', 32);
            $table->string('registration_normalized', 32);
            $table->string('registration_country', 2);
            $table->string('vin', 64)->nullable();
            $table->string('make', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('fuel_type', 30)->nullable();
            $table->unsignedSmallInteger('passenger_capacity')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->unique(['organization_id', 'id']);
        });
        DB::statement("CREATE UNIQUE INDEX vehicles_active_registration_unique ON vehicles (organization_id, registration_country, registration_normalized) WHERE status <> 'archived'");

        Schema::create('drivers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id')->nullable();
            $table->string('name', 160);
            $table->string('employee_code', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'employee_code']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('serial_number', 160);
            $table->string('name', 160)->nullable();
            $table->string('status', 24)->default('registered');
            $table->string('broker_principal', 160)->nullable()->unique();
            $table->string('app_version', 100)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'serial_number']);
        });

        Schema::create('device_installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('device_id');
            $table->uuid('vehicle_id');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->string('close_reason', 255)->nullable();
            $table->timestampsTz();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(['organization_id', 'device_id'])->references(['organization_id', 'id'])->on('devices')->restrictOnDelete();
            $table->foreign(['organization_id', 'vehicle_id'])->references(['organization_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->index(['organization_id', 'device_id', 'effective_from']);
            $table->index(['organization_id', 'vehicle_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_installations');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vehicles');
    }
};
