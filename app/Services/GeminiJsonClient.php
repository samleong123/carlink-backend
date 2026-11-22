<?php

namespace App\Services;

use Illuminate\Support\Facades\{Http, Log};

class GeminiJsonClient
{
    /**
     * Send a transient prompt to Google Gemini and return the decoded JSON object.
     * Prompts, payloads, and outputs are never persisted to the database.
     *
     * @return array{model:string,data:array<string,mixed>}
     */
    public function generateJson(string $prompt, string $featureLabel, int $maxOutputTokens = 2048): array
    {
        $key = trim((string) config('dtc_ai.key'));
        $model = trim((string) (config('dtc_ai.audit_model') ?: config('dtc_ai.model') ?: 'gemini-3.5-flash-lite'));
        $modelValid = (bool) preg_match('/^[a-zA-Z0-9._-]{1,100}$/D', $model);
        if (!$key || !$modelValid) {
            // Never log GEMINI_API_KEY or request payloads; record only configuration presence flags.
            Log::error("{$featureLabel} unavailable: GEMINI_API_KEY or model is not configured.", [
                'has_key' => $key !== '',
                'model_valid' => $modelValid,
            ]);
            abort(503, 'Configure Google AI credentials and model.');
        }

        $generationConfig = [
            'temperature' => 0.2,
            'maxOutputTokens' => $maxOutputTokens,
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
                Log::error("{$featureLabel} provider HTTP error.", [
                    'status' => $response->status(),
                    'provider_status' => is_array($err) ? ($err['status'] ?? null) : null,
                    'provider_message' => is_array($err) ? ($err['message'] ?? null) : null,
                ]);
                abort(502, "{$featureLabel} provider returned an error.");
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                abort(502, "{$featureLabel} provider returned non-JSON response.");
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
            Log::error("{$featureLabel} provider unavailable or incompatible.", ['exception' => get_class($e)]);
            abort(502, "{$featureLabel} provider unavailable or incompatible.");
        }

        if (!is_array($decoded)) {
            Log::error("{$featureLabel} provider returned malformed JSON structure.");
            abort(502, "{$featureLabel} provider returned malformed analysis.");
        }

        return [
            'model' => $model,
            'data' => $decoded,
        ];
    }

    /**
     * Strip potential email addresses or IPv4 addresses from free-text labels before external dispatch.
     */
    public function scrubText(?string $value, int $maxLength = 160): string
    {
        if ($value === null) {
            return '';
        }
        $clean = trim($value);
        if ($clean === '') {
            return '';
        }
        $clean = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $clean);
        $clean = (string) preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[redacted-ip]', $clean);
        return mb_substr($clean, 0, $maxLength);
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
