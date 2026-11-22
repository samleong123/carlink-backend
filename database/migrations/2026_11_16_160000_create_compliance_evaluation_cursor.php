<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('compliance_evaluation_cursors', function (Blueprint $table) {
            $table->string('name', 80)->primary();
            $table->uuid('last_obligation_id')->nullable();
            $table->timestampTz('updated_at');
        });
        Schema::create('compliance_alert_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('alert_occurrence_id');
            $table->string('from_state', 24);
            $table->string('to_state', 24);
            $table->string('reason', 80);
            $table->timestampTz('occurred_at');
            $table->foreign(['organization_id', 'alert_occurrence_id'], 'compliance_alert_transition_tenant_fk')
                ->references(['organization_id', 'id'])->on('alert_occurrences')->restrictOnDelete();
            $table->index(['organization_id', 'alert_occurrence_id', 'occurred_at'], 'compliance_alert_transition_lookup');
        });
        Schema::table('compliance_records', function (Blueprint $table) {
            $table->index(['organization_id', 'obligation_id', 'state', 'valid_from', 'expires_on'], 'compliance_evaluation_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('compliance_records', fn (Blueprint $table) => $table->dropIndex('compliance_evaluation_lookup'));
        Schema::dropIfExists('compliance_alert_transitions');
        Schema::dropIfExists('compliance_evaluation_cursors');
    }
};
