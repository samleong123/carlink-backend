<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_signup_intents',function (Blueprint $t) {
            $t->foreignUuid('token_id')->primary()->constrained('magic_link_tokens')->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $t->string('request_key_hash',64); $t->string('request_hash',64);
            $t->text('encrypted_payload')->nullable(); $t->timestampTz('expires_at')->index(); $t->timestampsTz();
            $t->unique(['user_id','request_key_hash']);
        });
    }
    public function down(): void
    {
        throw new \RuntimeException('Signup intents must be retained or expired through a reviewed forward migration; do not discard live email confirmations.');
    }
};
