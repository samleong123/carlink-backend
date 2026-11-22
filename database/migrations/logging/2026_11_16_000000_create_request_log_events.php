<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up(): void {
  Schema::connection('logging')->create('request_log_events', function (Blueprint $t) {
   $t->uuid('id')->primary(); $t->uuid('request_id')->index(); $t->string('event_type',16); $t->timestampTz('occurred_at')->index(); $t->json('data');
  });
 }
 public function down(): void { Schema::connection('logging')->dropIfExists('request_log_events'); }
};
