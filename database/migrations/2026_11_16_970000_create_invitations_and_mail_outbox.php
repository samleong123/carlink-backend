<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->string('email_normalized',255); $t->string('role_code',50);
            $t->string('token_hash',64)->unique(); $t->uuid('invited_by'); $t->timestampTz('expires_at');
            $t->string('request_key_hash',64); $t->string('request_hash',64); $t->unique(['organization_id','request_key_hash']);
            $t->timestampTz('accepted_at')->nullable(); $t->timestampTz('revoked_at')->nullable(); $t->timestampsTz();
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->foreign('role_code')->references('code')->on('roles')->restrictOnDelete();
            $t->foreign('invited_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['organization_id','email_normalized']);
        });
        Schema::create('mail_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id')->nullable(); $t->uuid('user_id');
            $t->string('kind',32); $t->uuid('source_id'); $t->text('encrypted_message')->nullable();
            $t->string('state',32)->default('pending'); $t->unsignedInteger('attempts')->default(0);
            $t->timestampTz('expires_at'); $t->timestampTz('available_at'); $t->timestampTz('last_dispatched_at')->nullable();
            $t->uuid('lease_token')->nullable(); $t->timestampTz('locked_until')->nullable(); $t->timestampTz('provider_accepted_at')->nullable();
            $t->string('last_error',64)->nullable(); $t->timestampsTz();
            $t->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['kind','source_id']); $t->index(['state','available_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('mail_outbox'); Schema::dropIfExists('organization_invitations'); }
};
