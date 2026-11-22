<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->string('gateway_id', 191)->nullable()->index();
            $table->string('gateway_currency', 3)->nullable();
            $table->text('checkout_url')->nullable();
            $table->string('callback_transaction_id', 191)->nullable();
            $table->string('callback_transaction_status', 32)->nullable();
            $table->string('callback_currency', 3)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropColumn(['gateway_id', 'gateway_currency', 'checkout_url', 'callback_transaction_id', 'callback_transaction_status', 'callback_currency']);
        });
    }
};
