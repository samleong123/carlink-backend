<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['callback_token_hash', 'checkout_url'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expires_at' => 'immutable_datetime', 'callback_received_at' => 'immutable_datetime', 'checkout_url' => 'encrypted'];
    }
}
