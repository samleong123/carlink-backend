<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_event_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('device_id');
            $t->uuid('event_id'); $t->uuid('installation_id'); $t->uuid('boot_id'); $t->unsignedBigInteger('sequence');
            $t->string('payload_hmac', 64); $t->string('decision', 48); $t->uuid('shift_id')->nullable();
            $t->timestampTz('occurred_at'); $t->timestampTz('received_at');
            $t->unique(['device_id', 'event_id']); $t->unique(['device_id', 'boot_id', 'sequence']);
            $t->foreign(['organization_id', 'device_id'])->references(['organization_id', 'id'])->on('devices')->restrictOnDelete();
        });
        Schema::create('device_event_quarantine', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('route_sha256', 64); $t->string('payload_hmac', 64);
            $t->string('reason', 64); $t->timestampTz('received_at');
        });
        Schema::create('device_event_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('receipt_id')->constrained('device_event_receipts')->restrictOnDelete();
            $t->string('kind', 32); $t->string('routing_key', 160); $t->json('payload');
            $t->timestampTz('expires_at')->nullable(); $t->unsignedInteger('attempts')->default(0);
            $t->unsignedInteger('generation')->default(1);
            $t->timestampTz('available_at'); $t->uuid('lease_token')->nullable(); $t->timestampTz('locked_until')->nullable();
            $t->timestampTz('published_at')->nullable(); $t->timestampTz('cancelled_at')->nullable(); $t->timestampTz('created_at');
            $t->unique(['receipt_id', 'kind']); $t->index(['published_at', 'available_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('device_event_outbox'); Schema::dropIfExists('device_event_quarantine'); Schema::dropIfExists('device_event_receipts');
    }
};
