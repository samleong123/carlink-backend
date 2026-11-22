<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_mutex', function (Blueprint $t) { $t->unsignedInteger('id')->primary(); });
        DB::table('platform_mutex')->insert(['id'=>1]);
        Schema::create('saas_admins', function (Blueprint $t) {
            $t->foreignUuid('user_id')->primary()->constrained('users')->restrictOnDelete();
            $t->string('role',20); $t->string('status',20)->default('active'); $t->unsignedInteger('version')->default(1); $t->timestampsTz();
        });
        Schema::create('subscription_plans', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name',100); $t->unsignedInteger('price_minor'); $t->string('currency',3)->default('MYR');
            $t->unsignedInteger('period_days')->default(30); $t->unsignedInteger('vehicle_limit'); $t->unsignedInteger('driver_limit');
            $t->unsignedInteger('retention_days'); $t->boolean('cellular_enabled'); $t->boolean('safety_enabled');
            $t->unsignedInteger('telemetry_min_seconds'); $t->string('status',20)->default('active'); $t->unsignedInteger('version')->default(1); $t->timestampsTz();
        });
        Schema::create('organization_subscriptions', function (Blueprint $t) {
            $t->foreignUuid('organization_id')->primary()->constrained('organizations')->restrictOnDelete();
            $t->foreignUuid('plan_id')->nullable()->constrained('subscription_plans')->restrictOnDelete();
            $t->json('plan_snapshot')->nullable(); $t->string('status',20)->default('pending');
            $t->timestampTz('expires_at')->nullable(); $t->unsignedInteger('version')->default(1); $t->timestampsTz();
        });
        Schema::create('billing_invoices', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('number',50)->unique(); $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->foreignUuid('plan_id')->constrained('subscription_plans')->restrictOnDelete(); $t->json('plan_snapshot');
            $t->unsignedInteger('amount_minor'); $t->string('currency',3); $t->string('status',20)->default('issued');
            $t->uuid('payment_id')->nullable()->unique(); $t->uuid('issued_by'); $t->timestampTz('paid_at')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestampsTz();
        });
        Schema::table('payment_attempts', function (Blueprint $t) {
            $t->foreignUuid('invoice_id')->nullable()->constrained('billing_invoices')->restrictOnDelete();
        });
        Schema::table('devices', function (Blueprint $t) {
            $t->uuid('obd_uuid')->nullable()->unique(); $t->uuid('registered_vehicle_id')->nullable()->unique();
            $t->text('auth_token_encrypted')->nullable(); $t->string('obd_bluetooth_mac',17)->nullable();
            $t->timestampTz('installed_at')->nullable(); $t->uuid('installed_by')->nullable();
        });
        Schema::table('drivers', function (Blueprint $t) { $t->string('photo_path')->nullable(); });
        Schema::create('device_commands', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('device_id'); $t->uuid('vehicle_id');
            $t->string('kind',40); $t->json('payload'); $t->string('signature',64); $t->string('state',20)->default('pending');
            $t->uuid('requested_by'); $t->timestampTz('expires_at'); $t->timestampTz('started_at')->nullable();
            $t->string('result_status',32)->nullable(); $t->timestampsTz(); $t->index(['device_id','state']);
        });
        Schema::create('safety_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('vehicle_id'); $t->uuid('driver_id')->nullable();
            $t->uuid('shift_id')->nullable(); $t->uuid('start_sample_id'); $t->uuid('end_sample_id');
            $t->string('kind',32); $t->decimal('acceleration_ms2',12,6); $t->decimal('threshold_ms2',12,6);
            $t->timestampTz('occurred_at',6); $t->string('quality',40); $t->string('state',20)->default('observed'); $t->timestampsTz();
            $t->unique(['start_sample_id','end_sample_id']); $t->index(['organization_id','driver_id','occurred_at']);
        });
        Schema::create('safety_appeals', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->foreignUuid('event_id')->unique()->constrained('safety_events')->cascadeOnDelete();
            $t->uuid('driver_id'); $t->uuid('submitted_by'); $t->text('reason'); $t->string('status',20)->default('pending');
            $t->uuid('decided_by')->nullable(); $t->text('decision_reason')->nullable(); $t->unsignedInteger('version')->default(1); $t->timestampsTz();
        });
        // Existing grants are preserved; tenants can assign module-specific staff roles.
        $roles = [
            'module_vehicle'=>['vehicles.read','vehicles.write','devices.read','telemetry.read','mileage.read','maintenance.read','maintenance.write','maintenance.complete','compliance.read','compliance.write','diagnostics.run','device_config.publish'],
            'module_driver'=>['drivers.read','drivers.write','shifts.read','safety.read'],
            'module_dashboard'=>['dashboard.read','telemetry.read','vehicles.read'],
            'module_compliance'=>['vehicles.read','compliance.read','compliance.write','compliance.verify','maintenance.read','mileage.read','safety.read','safety.appeals.decide'],
            'module_coverage'=>['vehicles.read','coverage.read','coverage.export'],
        ];
        foreach ($roles as $role=>$keys) {
            DB::table('roles')->insertOrIgnore(['code'=>$role]);
            foreach ($keys as $key) { DB::table('permissions')->insertOrIgnore(['key'=>$key]); DB::table('role_permissions')->insertOrIgnore(['role_code'=>$role,'permission_key'=>$key]); }
        }
        foreach (['owner','admin'] as $role) foreach (['subscription.manage','diagnostics.run'] as $key) {
            DB::table('permissions')->insertOrIgnore(['key'=>$key]); DB::table('role_permissions')->insertOrIgnore(['role_code'=>$role,'permission_key'=>$key]);
        }
        // No client history may retain diagnostic codes. Do not silently erase legacy records in an upgrade.
        // A separately acknowledged privacy purge command handles legacy DTC data after backup review.
    }
    public function down(): void { throw new LogicException('Use a reviewed forward rollback; billing and accepted telemetry must be preserved.'); }
};
