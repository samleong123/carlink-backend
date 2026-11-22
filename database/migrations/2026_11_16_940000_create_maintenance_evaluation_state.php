<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('maintenance_service_cycles', function (Blueprint $t) {
            $t->unsignedBigInteger('carry_in_distance_m')->nullable(); $t->timestampTz('carry_in_as_of')->nullable(); $t->string('carry_in_reason',500)->nullable();
        });
        Schema::table('alert_occurrences', function (Blueprint $t) { $t->json('evidence')->nullable(); });
        Schema::create('alert_transitions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('occurrence_id'); $t->string('action',40); $t->json('evidence'); $t->timestampTz('created_at');
            $t->foreign(['organization_id','occurrence_id'])->references(['organization_id','id'])->on('alert_occurrences')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('alert_transitions');
        Schema::table('alert_occurrences', function (Blueprint $t) { $t->dropColumn('evidence'); });
        Schema::table('maintenance_service_cycles', function (Blueprint $t) { $t->dropColumn(['carry_in_distance_m','carry_in_as_of','carry_in_reason']); });
    }
};
