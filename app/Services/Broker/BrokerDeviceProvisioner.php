<?php

namespace App\Services\Broker;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provisions per-device MQTT credentials and bounded vhost/topic ACLs in RabbitMQ
 * via its Management HTTP API using separate administrator credentials.
 */
class BrokerDeviceProvisioner
{
    public function enabled(): bool
    {
        return trim((string) config('broker.management.url', '')) !== '';
    }

    /**
     * @return array{enabled:bool,status:string,action:string,overridden:bool,principal:string,http_status:?int,message:string}
     */
    public function provisionDevice(object $device, bool $throwOnFailure = true): array
    {
        $principal = 'd-'.str_replace('-', '', (string) $device->id);

        if (!$this->enabled()) {
            return [
                'enabled' => false,
                'status' => 'disabled',
                'action' => 'skipped',
                'overridden' => false,
                'principal' => $principal,
                'http_status' => null,
                'message' => 'RABBITMQ_MANAGEMENT_URL is not configured.',
            ];
        }

        if (empty($device->auth_token_encrypted)) {
            return $this->fail(
                $device,
                $principal,
                'token_missing',
                409,
                'Device credential has not been generated.',
                409,
                $throwOnFailure,
            );
        }

        $baseUrl = $this->parseBaseUrl((string) config('broker.management.url', ''));
        if ($baseUrl === null) {
            return $this->fail(
                $device,
                $principal,
                'invalid_url',
                null,
                'RABBITMQ_MANAGEMENT_URL must be an HTTP/HTTPS URL without embedded credentials.',
                503,
                $throwOnFailure,
            );
        }

        $mgmtUser = trim((string) config('broker.management.user', ''));
        $mgmtPass = (string) config('broker.management.password', '');
        $vhost = trim((string) config('broker.vhost', ''));
        $exchange = trim((string) config('broker.exchange', 'amq.topic'));
        $timeout = max(1, min(30, (int) config('broker.management.timeout', 5)));

        if ($mgmtUser === '' || $mgmtPass === '' || $vhost === '' || $exchange === '') {
            return $this->fail(
                $device,
                $principal,
                'incomplete_config',
                null,
                'RabbitMQ Management provisioning credentials or vhost are incomplete.',
                503,
                $throwOnFailure,
            );
        }

        $token = Crypt::decryptString($device->auth_token_encrypted);

        $client = Http::withBasicAuth($mgmtUser, $mgmtPass)
            ->acceptJson()
            ->asJson()
            ->withoutRedirecting()
            ->connectTimeout(min(2, $timeout))
            ->timeout($timeout);

        $existing = $this->send($client, 'get', "{$baseUrl}/api/users/".rawurlencode($principal), $device, $principal, 'lookup_user', [404], null, $throwOnFailure);
        if (is_array($existing)) {
            return $existing;
        }

        $existed = $existing->status() === 200;
        if ($existed) {
            $tags = $existing->json('tags');
            $hasTags = is_array($tags) ? $tags !== [] : trim((string) $tags) !== '';
            if ($hasTags) {
                return $this->fail(
                    $device,
                    $principal,
                    'privileged_principal_conflict',
                    409,
                    'Target RabbitMQ principal already has management privileges.',
                    409,
                    $throwOnFailure,
                );
            }
        }

        $devEsc = preg_quote((string) $device->id, '/');
        $prefix = preg_quote("fleet.v1.{$device->organization_id}.{$device->id}", '/');
        $ownSub = "^mqtt-subscription-{$devEsc}qos[01]$";

        $userPut = $this->send(
            $client,
            'put',
            "{$baseUrl}/api/users/".rawurlencode($principal),
            $device,
            $principal,
            'put_user',
            [],
            ['password' => $token, 'tags' => ''],
            $throwOnFailure,
        );
        if (is_array($userPut)) {
            return $userPut;
        }

        $permPut = $this->send(
            $client,
            'put',
            "{$baseUrl}/api/permissions/".rawurlencode($vhost).'/'.rawurlencode($principal),
            $device,
            $principal,
            'put_permissions',
            [],
            [
                'configure' => $ownSub,
                'write' => "^(amq\\.topic|mqtt-subscription-{$devEsc}qos[01])$",
                'read' => "^(amq\\.topic|mqtt-subscription-{$devEsc}qos[01])$",
            ],
            $throwOnFailure,
        );
        if (is_array($permPut)) {
            return $permPut;
        }

        $topicPut = $this->send(
            $client,
            'put',
            "{$baseUrl}/api/topic-permissions/".rawurlencode($vhost).'/'.rawurlencode($principal),
            $device,
            $principal,
            'put_topic_permissions',
            [],
            [
                'exchange' => $exchange,
                'write' => "^{$prefix}\\.up\\.(telemetry|events)$",
                'read' => "^{$prefix}\\.down\\.(ingest_ack|results)$",
            ],
            $throwOnFailure,
        );
        if (is_array($topicPut)) {
            return $topicPut;
        }

        return [
            'enabled' => true,
            'status' => 'success',
            'action' => $existed ? 'overridden' : 'created',
            'overridden' => $existed,
            'principal' => $principal,
            'http_status' => $userPut->status(),
            'message' => $existed
                ? 'Existing RabbitMQ device user and ACLs overridden.'
                : 'RabbitMQ device user and ACLs created.',
        ];
    }

    private function parseBaseUrl(string $raw): ?string
    {
        $trimmed = rtrim(trim($raw), '/');
        $parts = parse_url($trimmed);
        if (
            !$parts
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        return preg_replace('#/api$#i', '', $trimmed);
    }

    private function send(
        $client,
        string $method,
        string $url,
        object $device,
        string $principal,
        string $step,
        array $allowedErrors,
        ?array $payload,
        bool $throwOnFailure,
    ) {
        try {
            $response = $payload === null ? $client->{$method}($url) : $client->{$method}($url, $payload);
        } catch (\Throwable $e) {
            return $this->fail(
                $device,
                $principal,
                $step,
                null,
                'RabbitMQ management service is unreachable ('.class_basename($e).').',
                503,
                $throwOnFailure,
                ['error_type' => class_basename($e)],
            );
        }

        if ($response->successful() || in_array($response->status(), $allowedErrors, true)) {
            return $response;
        }

        $rmqReason = trim((string) ($response->json('reason') ?? $response->json('error') ?? ''));
        $detail = $rmqReason !== '' ? substr($rmqReason, 0, 160) : 'HTTP '.$response->status();

        return $this->fail(
            $device,
            $principal,
            $step,
            $response->status(),
            'RabbitMQ Management API failed at '.$step.': '.$detail,
            503,
            $throwOnFailure,
            ['rmq_error' => substr((string) ($response->json('error') ?? ''), 0, 80)],
        );
    }

    private function fail(
        object $device,
        string $principal,
        string $step,
        ?int $httpStatus,
        string $message,
        int $abortCode,
        bool $throwOnFailure,
        array $extraContext = [],
    ): array {
        Log::error('RabbitMQ device provisioning failed', [
            'device_id' => (string) $device->id,
            'organization_id' => (string) ($device->organization_id ?? ''),
            'principal' => $principal,
            'step' => $step,
            'http_status' => $httpStatus,
            'message' => $message,
        ] + $extraContext);

        if ($throwOnFailure) {
            abort($abortCode, $message);
        }

        return [
            'enabled' => true,
            'status' => 'failed',
            'action' => 'failed',
            'overridden' => false,
            'principal' => $principal,
            'http_status' => $httpStatus,
            'message' => $message,
        ];
    }
}
