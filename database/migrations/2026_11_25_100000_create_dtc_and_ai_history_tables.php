<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dtc_catalog', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('component', 16);
            $table->text('description');
            $table->text('possible_solution');
            $table->string('model', 100)->nullable();
            $table->string('source', 32)->default('gemini_ai');
            $table->unsignedBigInteger('lookup_count')->default(1);
            $table->timestampTz('first_analyzed_at');
            $table->timestampTz('last_analyzed_at');
            $table->timestampsTz();
        });

        Schema::create('vehicle_dtc_episodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('vehicle_id');
            $table->uuid('installation_id')->nullable();
            $table->string('code', 32);
            $table->string('status', 20)->default('active');
            $table->boolean('mil_on')->default(false);
            $table->timestampTz('started_at', 6);
            $table->timestampTz('last_observed_at', 6);
            $table->timestampTz('ended_at', 6)->nullable();
            $table->uuid('start_sample_id')->nullable();
            $table->uuid('end_sample_id')->nullable();
            $table->unsignedInteger('samples_count')->default(1);
            $table->timestampTz('email_notified_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['organization_id', 'vehicle_id'])
                ->references(['organization_id', 'id'])
                ->on('vehicles')
                ->restrictOnDelete();
            $table->index(['organization_id', 'vehicle_id', 'status', 'code'], 'idx_dtc_episodes_org_veh_status_code');
            $table->index(['organization_id', 'started_at'], 'idx_dtc_episodes_org_started');
        });

        Schema::create('ai_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->uuid('driver_id')->nullable();
            $table->string('feature', 48);
            $table->string('target_key', 160)->nullable();
            $table->string('title', 255);
            $table->string('model', 100);
            $table->text('parameters');
            $table->text('result_payload');
            $table->timestampTz('created_at');

            $table->index(['organization_id', 'feature', 'created_at'], 'idx_ai_gen_org_feature_created');
            $table->index(['feature', 'driver_id', 'created_at'], 'idx_ai_gen_feature_driver_created');
            $table->index(['feature', 'created_at'], 'idx_ai_gen_feature_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
        Schema::dropIfExists('vehicle_dtc_episodes');
        Schema::dropIfExists('dtc_catalog');
    }
};
