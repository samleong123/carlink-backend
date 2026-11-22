<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('http_request_logs', function (Blueprint $t) {
            $t->uuid('request_id')->primary(); $t->timestampTz('started_at')->nullable(); $t->timestampTz('completed_at')->nullable();
            $t->string('state', 24); $t->uuid('organization_id')->nullable(); $t->uuid('user_id')->nullable(); $t->uuid('membership_id')->nullable();
            $t->string('method', 16)->nullable(); $t->string('route', 255)->nullable(); $t->unsignedSmallInteger('http_status')->nullable();
            $t->unsignedBigInteger('duration_ms')->nullable(); $t->json('context');
            $t->index(['organization_id', 'started_at', 'request_id']); $t->index(['user_id', 'started_at']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id')->nullable(); $t->uuid('user_id')->nullable(); $t->uuid('membership_id')->nullable();
            $t->uuid('request_id')->nullable(); $t->uuid('origin_request_id')->nullable(); $t->string('source', 24);
            $t->string('action_name', 255); $t->string('outcome', 24); $t->timestampTz('occurred_at'); $t->json('context');
            $t->index(['organization_id', 'occurred_at', 'id']); $t->index('request_id'); $t->index('origin_request_id');
        });
        Schema::create('log_ingestion_receipts', function (Blueprint $t) { $t->uuid('event_id')->primary(); $t->string('checksum', 64); $t->timestampTz('received_at'); });
    }

    public function down(): void
    {
        foreach (['log_ingestion_receipts', 'audit_logs', 'http_request_logs'] as $table) Schema::dropIfExists($table);
    }
};
