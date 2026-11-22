<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('maintenance_plans', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->string('name', 160); $t->string('service_type', 80);
            $t->string('status', 16)->default('active'); $t->unsignedInteger('current_revision')->default(1); $t->timestampsTz();
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete(); $t->unique(['organization_id', 'id']);
        });
        Schema::create('maintenance_plan_revisions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('plan_id'); $t->unsignedInteger('revision');
            $t->json('interval'); $t->json('thresholds'); $t->uuid('created_by'); $t->timestampsTz();
            $t->foreign(['organization_id','plan_id'])->references(['organization_id','id'])->on('maintenance_plans')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete(); $t->unique(['plan_id','revision']);
        });
        Schema::create('vehicle_maintenance_plans', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('vehicle_id'); $t->uuid('plan_id');
            $t->string('status', 16)->default('active'); $t->unsignedInteger('version')->default(1); $t->timestampsTz();
            $t->foreign(['organization_id','vehicle_id'])->references(['organization_id','id'])->on('vehicles')->restrictOnDelete();
            $t->foreign(['organization_id','plan_id'])->references(['organization_id','id'])->on('maintenance_plans')->restrictOnDelete();
            $t->unique(['organization_id','id']); $t->unique(['organization_id','vehicle_id','plan_id']);
        });
        Schema::create('maintenance_service_cycles', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('assignment_id'); $t->unsignedInteger('ordinal');
            $t->timestampTz('serviced_at')->nullable(); $t->timestampTz('ended_at')->nullable(); $t->json('interval_snapshot'); $t->json('thresholds_snapshot');
            $t->string('baseline_quality', 32)->default('unknown_baseline'); $t->timestampsTz();
            $t->foreign(['organization_id','assignment_id'])->references(['organization_id','id'])->on('vehicle_maintenance_plans')->restrictOnDelete();
            $t->unique(['organization_id','id']); $t->unique(['assignment_id','ordinal']);
        });
        Schema::create('maintenance_work_orders', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('vehicle_id'); $t->string('number', 80);
            $t->string('status', 20)->default('draft'); $t->string('workshop_name',160)->nullable(); $t->timestampTz('scheduled_at')->nullable();
            $t->unsignedInteger('version')->default(1); $t->string('cancel_reason', 500)->nullable(); $t->string('completion_idempotency_hash',64)->nullable(); $t->string('completion_payload_hash',64)->nullable(); $t->timestampsTz();
            $t->foreign(['organization_id','vehicle_id'])->references(['organization_id','id'])->on('vehicles')->restrictOnDelete();
            $t->unique(['organization_id','id']); $t->unique(['organization_id','number']);
        });
        Schema::create('maintenance_work_order_items', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('work_order_id'); $t->uuid('assignment_id'); $t->uuid('cycle_id');
            $t->string('status', 16)->default('pending'); $t->timestampsTz();
            $t->foreign(['organization_id','work_order_id'])->references(['organization_id','id'])->on('maintenance_work_orders')->restrictOnDelete();
            $t->foreign(['organization_id','assignment_id'])->references(['organization_id','id'])->on('vehicle_maintenance_plans')->restrictOnDelete();
            $t->foreign(['organization_id','cycle_id'])->references(['organization_id','id'])->on('maintenance_service_cycles')->restrictOnDelete();
            $t->unique(['organization_id','id']); $t->unique(['work_order_id','assignment_id']);
        });
        Schema::create('maintenance_service_records', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('vehicle_id'); $t->uuid('work_order_id'); $t->uuid('work_order_item_id');
            $t->timestampTz('performed_at'); $t->decimal('odometer_km', 16, 3); $t->string('odometer_source',100); $t->string('workshop_name',160);
            $t->bigInteger('cost_minor')->nullable(); $t->string('currency',3)->nullable(); $t->text('notes')->nullable(); $t->uuid('entered_by'); $t->timestampsTz();
            $t->foreign(['organization_id','vehicle_id'])->references(['organization_id','id'])->on('vehicles')->restrictOnDelete();
            $t->foreign(['organization_id','work_order_id'])->references(['organization_id','id'])->on('maintenance_work_orders')->restrictOnDelete();
            $t->foreign(['organization_id','work_order_item_id'])->references(['organization_id','id'])->on('maintenance_work_order_items')->restrictOnDelete();
            $t->foreign('entered_by')->references('id')->on('users')->restrictOnDelete(); $t->unique(['work_order_item_id']);
        });
        Schema::create('maintenance_action_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('work_order_id'); $t->uuid('actor_id'); $t->string('event', 60); $t->timestampsTz();
            $t->foreign(['organization_id','work_order_id'])->references(['organization_id','id'])->on('maintenance_work_orders')->restrictOnDelete();
            $t->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        foreach (['maintenance_action_events','maintenance_service_records','maintenance_work_order_items','maintenance_work_orders','maintenance_service_cycles','vehicle_maintenance_plans','maintenance_plan_revisions','maintenance_plans'] as $table) Schema::dropIfExists($table);
    }
};
