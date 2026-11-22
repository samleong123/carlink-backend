<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('compliance_types', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->string('code', 80); $t->string('name', 160);
            $t->string('subject_type', 10); $t->unsignedInteger('warning_days')->default(30); $t->boolean('active')->default(true); $t->unsignedInteger('version')->default(1); $t->timestampsTz();
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->unique(['organization_id','id']); $t->unique(['organization_id','code']);
        });
        Schema::create('compliance_obligations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('compliance_type_id');
            $t->string('subject_type',10); $t->uuid('vehicle_id')->nullable(); $t->uuid('driver_id')->nullable();
            $t->string('applicability',20)->default('applicable'); $t->unsignedInteger('version')->default(1); $t->timestampsTz();
            $t->foreign(['organization_id','compliance_type_id'])->references(['organization_id','id'])->on('compliance_types')->restrictOnDelete();
            $t->foreign(['organization_id','vehicle_id'])->references(['organization_id','id'])->on('vehicles')->restrictOnDelete();
            $t->foreign(['organization_id','driver_id'])->references(['organization_id','id'])->on('drivers')->restrictOnDelete();
            $t->unique(['organization_id','id']); $t->unique(['organization_id','compliance_type_id','vehicle_id']); $t->unique(['organization_id','compliance_type_id','driver_id']);
        });
        Schema::create('compliance_records', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('obligation_id'); $t->unsignedInteger('cycle_number');
            $t->string('reference_number',160)->nullable(); $t->string('issuing_authority',160)->nullable();
            $t->date('issued_on')->nullable(); $t->date('valid_from'); $t->date('expires_on');
            $t->string('evidence_reference',255)->nullable(); $t->string('state',20)->default('pending');
            $t->uuid('verified_by')->nullable(); $t->timestampTz('verified_at')->nullable();
            $t->timestampTz('suspended_at')->nullable(); $t->string('suspension_reason',500)->nullable();
            $t->string('idempotency_hash',64); $t->string('payload_hash',64); $t->timestampsTz();
            $t->foreign(['organization_id','obligation_id'])->references(['organization_id','id'])->on('compliance_obligations')->restrictOnDelete();
            $t->foreign('verified_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['organization_id','id']); $t->unique(['obligation_id','cycle_number']); $t->unique(['obligation_id','idempotency_hash']);
        });
        Schema::create('compliance_action_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('obligation_id'); $t->uuid('record_id')->nullable();
            $t->uuid('actor_id'); $t->string('event',60); $t->json('details')->nullable(); $t->timestampsTz();
            $t->foreign(['organization_id','obligation_id'])->references(['organization_id','id'])->on('compliance_obligations')->restrictOnDelete();
            $t->foreign(['organization_id','record_id'])->references(['organization_id','id'])->on('compliance_records')->restrictOnDelete();
            $t->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        foreach (['compliance_action_events','compliance_records','compliance_obligations','compliance_types'] as $table) Schema::dropIfExists($table);
    }
};
