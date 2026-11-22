<?php

namespace App\Services;

use Illuminate\Support\Facades\{Http, Log};

class AuditAi
{
    private const SECRET_KEY_PATTERN = '/token|secret|password|cookie|authorization|credential|auth_token|signature|body|payload|raw_payload|content_base64|dtc|possible_solution|payment_url|callback|client_hints|proxy_chain|idempotency_key_hash|email|ip_address|peer_ip|user_agent/i';

    public function explain(array $auditRecords): array
    {
        $key = trim((string) config('dtc_ai.key'));
        $model = trim((string) (config('dtc_ai.audit_model') ?: config('dtc_ai.model') ?: 'gemini-3.5-flash-lite'));
        $modelValid = (bool) preg_match('/^[a-zA-Z0-9._-]{1,100}$/D', $model);
        if (!$key || !$modelValid) {
            // Never log GEMINI_API_KEY or audit payloads; record only configuration presence flags.
            Log::error('Audit AI unavailable: GEMINI_API_KEY or AUDIT_AI_MODEL is not configured.', [
                'has_key' => $key !== '',
                'model_valid' => $modelValid,
            ]);
            abort(503, 'Configure Google AI credentials and audit model.');
        }

        $safeRecords = array_values(array_map(fn ($record) => $this->safeRecord((array) $record), $auditRecords));
        if ($safeRecords === []) {
            abort(422, 'At least one audit record is required.');
        }

        $recordsJson = json_encode($safeRecords, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $prompt = implode("\n", [
            'You are a fleet platform security and operations analyst.',
            'Explain what the following selected CarLink Action Audit records are doing in clear, plain operational language.',
            'Return ONLY a valid JSON object (no markdown fences or commentary outside JSON) with this exact shape:',
            '{"summary":"2-4 concise sentences describing overall activity, actors, outcomes, and any anomalies or failures.","items":[{"id":"<exact record id>","action":"<exact action_name>","summary":"1-2 sentences explaining what this specific request/action did.","intent":"Short operational intent label","risk_or_status":"Short status/risk note (e.g. Normal · Succeeded, Access denied (403), Failed)"}]}',
            'Include one entry in "items" for each input record, preserving each exact "id".',
            'Audit records:',
            $recordsJson,
        ]);

        $generationConfig = [
            'temperature' => 0.2,
            'maxOutputTokens' => 1536,
        ];
        if (preg_match('/^(?:gemma-4|gemini-2\.5|gemini-3)/i', $model)) {
            $generationConfig['thinkingConfig'] = ['thinkingLevel' => 'LOW'];
        }

        $makeBody = static fn (array $genCfg) => [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => $genCfg,
        ];

        // No query-string key, request logging, caching, persisted prompt, or blind retries.
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        try {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
            $timeout = max(10, min(95, (int) config('dtc_ai.timeout', 90)));
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout($timeout)
                ->post($url, $makeBody($generationConfig));

            if ($response->status() === 400 && isset($generationConfig['thinkingConfig'])) {
                $fallbackConfig = $generationConfig;
                unset($fallbackConfig['thinkingConfig']);
                $response = Http::withHeaders(['x-goog-api-key' => $key])
                    ->acceptJson()
                    ->connectTimeout(5)
                    ->timeout($timeout)
                    ->post($url, $makeBody($fallbackConfig));
            }

            if (!$response->successful() || strlen($response->body()) > 1048576) {
                $err = $response->json('error');
                Log::error('Audit AI provider HTTP error.', [
                    'status' => $response->status(),
                    'provider_status' => is_array($err) ? ($err['status'] ?? null) : null,
                    'provider_message' => is_array($err) ? ($err['message'] ?? null) : null,
                ]);
                abort(502, 'Audit AI provider returned an error.');
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                abort(502, 'Audit AI provider returned non-JSON response.');
            }

            $chunks = array_is_list($payload) ? $payload : [$payload];
            $text = '';
            foreach ($chunks as $chunk) {
                foreach ($chunk['candidates'][0]['content']['parts'] ?? [] as $part) {
                    if (!($part['thought'] ?? false) && is_string($part['text'] ?? null)) {
                        $text .= $part['text'];
                    }
                }
            }

            $decoded = $this->decodeModelJson($text);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Audit AI provider unavailable or incompatible.', ['exception' => get_class($e)]);
            abort(502, 'Audit AI provider unavailable or incompatible.');
        }

        if (!is_array($decoded) || !is_string($decoded['summary'] ?? null) || trim($decoded['summary']) === '' || !is_array($decoded['items'] ?? null)) {
            Log::error('Audit AI provider returned malformed JSON structure.');
            abort(502, 'Audit AI provider returned malformed analysis.');
        }

        $safeById = [];
        foreach ($safeRecords as $rec) {
            if ($rec['id'] !== '') {
                $safeById[$rec['id']] = $rec;
            }
        }

        $items = [];
        foreach ($decoded['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            $fallback = $id !== '' && isset($safeById[$id]) ? $safeById[$id] : null;
            $action = trim((string) ($item['action'] ?? ($fallback['action_name'] ?? '')));
            $itemSummary = trim((string) ($item['summary'] ?? ''));
            $intent = trim((string) ($item['intent'] ?? ''));
            $riskOrStatus = trim((string) ($item['risk_or_status'] ?? ''));
            if ($itemSummary === '') {
                continue;
            }
            $items[] = [
                'id' => $id,
                'action' => $action !== '' ? $action : 'unknown',
                'summary' => $itemSummary,
                'intent' => $intent !== '' ? $intent : 'Operational action',
                'risk_or_status' => $riskOrStatus !== '' ? $riskOrStatus : (string) ($fallback['outcome'] ?? 'observed'),
            ];
        }

        return [
            'model' => $model,
            'summary' => trim($decoded['summary']),
            'items' => $items,
        ];
    }

    private function safeRecord(array $record): array
    {
        $userId = $record['actor_user_id'] ?? $record['user_id'] ?? null;
        $source = is_string($record['source'] ?? null) && trim($record['source']) !== '' ? trim($record['source']) : 'system';

        $actorLabel = 'System (' . $source . ')';
        if (is_string($userId) && $userId !== '') {
            $rawName = is_string($record['actor_name'] ?? null) ? trim($record['actor_name']) : '';
            // Strip any parenthesized email address before sending metadata externally.
            $withoutEmail = trim((string) preg_replace('/\s*\([^@)]+@[^)]+\)\s*$/', '', $rawName));
            if ($withoutEmail !== '' && !str_contains($withoutEmail, '@')) {
                $actorLabel = $withoutEmail;
            } else {
                $actorLabel = 'Authenticated member';
            }
        }

        $context = is_array($record['context'] ?? null) ? $record['context'] : (is_object($record['context'] ?? null) ? (array) $record['context'] : []);

        return [
            'id' => (string) ($record['id'] ?? ''),
            'occurred_at' => $record['occurred_at'] ?? null,
            'action_name' => $record['action_name'] ?? $record['action'] ?? null,
            'outcome' => $record['outcome'] ?? null,
            'source' => $source,
            'method' => $record['method'] ?? null,
            'route' => $record['route'] ?? null,
            'http_status' => $record['http_status'] ?? $record['status'] ?? null,
            'actor_label' => $actorLabel,
            'context' => (object) $this->sanitizeContext($context),
        ];
    }

    private function sanitizeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $k => $v) {
            if (!is_string($k) || preg_match(self::SECRET_KEY_PATTERN, $k)) {
                continue;
            }
            if (is_array($v)) {
                $nested = $this->sanitizeContext($v);
                if ($nested !== []) {
                    $safe[$k] = $nested;
                }
            } elseif (is_scalar($v) || $v === null) {
                $safe[$k] = is_string($v) ? substr($v, 0, 256) : $v;
            }
        }
        return $safe;
    }

    private function decodeModelJson(string $text): ?array
    {
        $clean = trim($text);
        if ($clean === '') {
            return null;
        }
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $clean, $matches)) {
            $clean = trim($matches[1]);
        }
        $decoded = json_decode($clean, true, 32);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Fallback: extract the outermost JSON object if Gemini included surrounding text.
        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $slice = substr($clean, $start, $end - $start + 1);
            $decoded = json_decode($slice, true, 32);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }
}
