<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('payee_name', 200);
            $table->string('transaction_details', 500);
            $table->decimal('amount', 12, 2);
            $table->string('status', 32)->index();
            $table->string('failure_reason', 64)->nullable();
            $table->string('callback_token_hash', 64);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('callback_received_at')->nullable();
            $table->integer('provider_http_status')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'id']);
        });

        Schema::create('payment_attempt_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payment_attempt_id')->index();
            $table->uuid('organization_id')->index();
            $table->string('event', 64);
            $table->json('details')->nullable();
            $table->timestampTz('created_at');
            $table->foreign('payment_attempt_id')->references('id')->on('payment_attempts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempt_events');
        Schema::dropIfExists('payment_attempts');
    }
};
