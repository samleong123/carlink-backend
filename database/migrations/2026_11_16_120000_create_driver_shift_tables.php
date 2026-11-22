<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX installation_org_id_unique ON device_installations (organization_id, id)');
        Schema::create('nfc_key_configs', function (Blueprint $table) {
            $table->uuid('organization_id')->primary();
            $table->string('key_version', 50);
            $table->string('key_check', 64);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });
        Schema::create('driver_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('driver_id');
            $table->string('credential_hmac', 64);
            $table->string('hash_key_version', 50);
            $table->string('type', 40);
            $table->string('assurance', 40)->default('uid_low');
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['organization_id', 'driver_id'])->references(['organization_id', 'id'])->on('drivers')->restrictOnDelete();
            $table->unique(['organization_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX active_driver_credential_unique ON driver_credentials (organization_id, credential_hmac) WHERE revoked_at IS NULL');

        Schema::create('driver_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('driver_id');
            $table->uuid('vehicle_id');
            $table->uuid('credential_id');
            $table->uuid('device_installation_id');
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('last_authenticated_at');
            $table->timestampTz('attribution_expires_at');
            $table->string('state', 24);
            $table->string('closure_reason', 80)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->foreign(['organization_id', 'driver_id'])->references(['organization_id', 'id'])->on('drivers')->restrictOnDelete();
            $table->foreign(['organization_id', 'vehicle_id'])->references(['organization_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['organization_id', 'credential_id'])->references(['organization_id', 'id'])->on('driver_credentials')->restrictOnDelete();
            $table->foreign(['organization_id', 'device_installation_id'])->references(['organization_id', 'id'])->on('device_installations')->restrictOnDelete();
            $table->unique(['organization_id', 'id']);
        });
        DB::statement("CREATE UNIQUE INDEX active_shift_per_vehicle ON driver_shifts (organization_id, vehicle_id) WHERE state = 'active'");
        DB::statement("CREATE UNIQUE INDEX active_shift_per_driver ON driver_shifts (organization_id, driver_id) WHERE state = 'active'");

        Schema::create('driver_shift_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('shift_id');
            $table->uuid('actor_user_id');
            $table->string('event', 40);
            $table->string('reason', 255);
            $table->timestampTz('created_at');
            $table->foreign(['organization_id', 'shift_id'])->references(['organization_id', 'id'])->on('driver_shifts')->restrictOnDelete();
        });

        Schema::create('driver_auth_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('device_id');
            $table->uuid('event_id');
            $table->uuid('local_session_id');
            $table->uuid('credential_id')->nullable();
            $table->uuid('shift_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->string('decision', 48);
            $table->string('request_hmac', 64);
            $table->timestampsTz();
            $table->foreign(['organization_id', 'device_id'])->references(['organization_id', 'id'])->on('devices')->restrictOnDelete();
            $table->unique(['device_id', 'event_id']);
        });

        Schema::create('driver_credential_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('credential_id');
            $table->uuid('actor_user_id');
            $table->string('event', 40);
            $table->timestampTz('created_at');
            $table->foreign(['organization_id', 'credential_id'])->references(['organization_id', 'id'])->on('driver_credentials')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_credential_events');
        Schema::dropIfExists('driver_auth_events');
        Schema::dropIfExists('driver_shift_events');
        Schema::dropIfExists('driver_shifts');
        Schema::dropIfExists('driver_credentials');
        Schema::dropIfExists('nfc_key_configs');
        DB::statement('DROP INDEX installation_org_id_unique');
    }
};
