<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users',function(Blueprint $t) { $t->string('timezone',64)->default('Asia/Kuala_Lumpur'); $t->unsignedInteger('version')->default(1); });
        Schema::create('email_notifications',function(Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $t->string('event_key',64); $t->string('deduplication_hash',64)->unique();
            $t->string('scope',20); $t->uuid('scope_id')->nullable(); $t->unsignedInteger('scope_version')->nullable();
            $t->json('permissions'); $t->uuid('driver_id')->nullable(); $t->uuid('alert_id')->nullable();
            $t->boolean('safety_required')->default(false); $t->boolean('subscription_required')->default(true);
            $t->timestampTz('period_start')->nullable(); $t->timestampTz('occurred_at'); $t->timestampsTz();
            $t->string('resource_type',24)->nullable(); $t->uuid('resource_id')->nullable(); $t->unsignedInteger('resource_version')->nullable();
            $t->unsignedInteger('retention_days')->nullable();
            $t->index(['organization_id','user_id','event_key']);
        });
        Schema::create('email_score_baselines',function(Blueprint $t) {
            $t->uuid('organization_id'); $t->uuid('driver_id'); $t->uuid('user_id'); $t->unsignedInteger('score')->nullable();
            $t->string('basis',64); $t->date('checked_on'); $t->timestampsTz(); $t->primary(['organization_id','driver_id']);
        });
    }
    public function down(): void { throw new LogicException('Email audit metadata requires a reviewed forward rollback.'); }
};
