<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up(): void {
  Schema::create('users', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('email_normalized')->unique(); $t->string('display_name'); $t->timestampTz('email_verified_at')->nullable(); $t->string('locale',12)->default('en'); $t->string('status')->default('active'); $t->timestampTz('last_login_at')->nullable(); $t->timestampsTz(); });
  Schema::create('organizations', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('slug')->unique(); $t->string('name'); $t->string('status')->default('active'); $t->string('timezone')->default('Asia/Kuala_Lumpur'); $t->string('default_locale',12)->default('en'); $t->string('contact_email')->nullable(); $t->string('country_code',2)->nullable(); $t->unsignedInteger('version')->default(1); $t->timestampsTz(); });
  Schema::create('memberships', function (Blueprint $t) { $t->uuid('id')->primary(); $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete(); $t->foreignUuid('user_id')->constrained('users')->restrictOnDelete(); $t->string('role'); $t->string('status')->default('active'); $t->timestampTz('joined_at')->nullable(); $t->timestampsTz(); $t->unique(['organization_id','user_id']); });
  Schema::create('magic_link_tokens', function (Blueprint $t) { $t->uuid('id')->primary(); $t->foreignUuid('user_id')->constrained('users')->restrictOnDelete(); $t->string('email_normalized'); $t->string('token_hash',64)->unique(); $t->string('purpose')->default('login'); $t->timestampTz('expires_at'); $t->timestampTz('consumed_at')->nullable(); $t->string('return_path')->default('/'); $t->timestampsTz(); });
  Schema::create('sessions', function (Blueprint $t) { $t->string('id')->primary(); $t->uuid('user_id')->nullable()->index(); $t->string('ip_address',45)->nullable(); $t->text('user_agent')->nullable(); $t->longText('payload'); $t->integer('last_activity')->index(); });
 }
 public function down(): void { Schema::dropIfExists('sessions'); Schema::dropIfExists('magic_link_tokens'); Schema::dropIfExists('memberships'); Schema::dropIfExists('organizations'); Schema::dropIfExists('users'); }
};
