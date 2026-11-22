<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_signups', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            // Historical identifiers deliberately have no tenant FK: the tenant is physically deleted.
            $t->uuid('organization_id')->unique(); $t->uuid('invoice_id');
            $t->string('request_key_hash',64); $t->string('request_hash',64); $t->unique(['user_id','request_key_hash']);
            $t->string('status',20)->default('pending'); $t->timestampTz('payment_due_at');
            $t->timestampTz('paid_at')->nullable(); $t->timestampTz('deleted_at')->nullable(); $t->timestampsTz();
            $t->index(['status','payment_due_at']); $t->index(['user_id','status']);
        });
        Schema::create('signup_financial_archives', function (Blueprint $t) {
            $t->foreignUuid('signup_id')->primary()->constrained('organization_signups')->restrictOnDelete();
            $t->text('encrypted_snapshot'); $t->timestampTz('archived_at');
        });
        Schema::create('signup_payment_archives', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('signup_id')->constrained('organization_signups')->restrictOnDelete();
            $t->uuid('organization_id')->index(); $t->string('callback_token_hash',64);
            $t->decimal('amount',12,2); $t->string('currency',3); $t->string('gateway_id',191)->nullable();
            $t->string('gateway_currency',3)->nullable(); $t->integer('provider_http_status')->nullable();
            $t->string('status',32); $t->boolean('reconciliation_required')->default(false);
            $t->timestampTz('late_callback_at')->nullable(); $t->timestampTz('archived_at');
        });
        Schema::create('signup_archive_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('signup_id')->constrained('organization_signups')->restrictOnDelete();
            $t->uuid('payment_id')->nullable(); $t->string('event',64); $t->json('details'); $t->timestampTz('created_at');
            $t->index(['signup_id','created_at']);
        });
    }
    public function down(): void { throw new LogicException('Use a reviewed forward migration; signup financial and audit evidence must survive.'); }
};
