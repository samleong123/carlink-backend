<?php

namespace App\Services;

class DriverCredentials
{
    public function hmac(string $organization, string $fingerprint): string
    {
        $key = config('nfc.hmac_key');
        if (!is_string($key) || strlen($key) < 32) {
            throw new \RuntimeException('NFC HMAC key must be configured with at least 32 bytes.');
        }
        return hash_hmac('sha256', $organization.':'.strtolower($fingerprint), $key);
    }

    public function keyCheck(string $organization): string
    {
        $key = config('nfc.hmac_key');
        if (!is_string($key) || strlen($key) < 32) throw new \RuntimeException('NFC HMAC key must be configured with at least 32 bytes.');
        return hash_hmac('sha256', 'carlink-nfc-key-check:'.$organization, $key);
    }
}
