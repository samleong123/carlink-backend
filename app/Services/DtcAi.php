<?php

namespace App\Services;

use App\Exceptions\AiProviderUnavailable;
use Illuminate\Support\Facades\{Http, Log};
use Symfony\Component\HttpKernel\Exception\HttpException;

class DtcAi
{
    public function explain(string $code): array
    {
        $key = trim((string) config('dtc_ai.key'));
        $model = trim((string) config('dtc_ai.model'));
        $modelValid = (bool) preg_match('/^[a-zA-Z0-9._-]{1,100}$/D', $model);
        if (!$key || !$modelValid) {
            // Never log GEMINI_API_KEY or fault codes; record only configuration presence flags.
            Log::error('DTC AI unavailable: GEMINI_API_KEY or DTC_AI_MODEL is not configured.', [
                'has_key' => $key !== '',
                'model_valid' => $modelValid,
            ]);
            abort(503, 'Configure Google AI credentials and model.');
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'component' => ['type' => 'string', 'enum' => ['TCM', 'ECU', 'OBD']],
                'possible_solution' => ['type' => 'string'],
            ],
            'required' => ['code', 'description', 'component', 'possible_solution'],
        ];

        $generationConfig = [
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
        ];
        if (preg_match('/^(?:gemma-4|gemini-2\.5|gemini-3)/i', $model)) {
            $generationConfig['thinkingConfig'] = ['thinkingLevel' => 'LOW'];
        }

        $prompt = "What is $code fault code? Component must be TCM, ECU or OBD. possible_solution must be strictly between 20 and 30 words (aim for 25 words). description must be at most 50 words. Return only the requested JSON with keys code, description, component, possible_solution. Treat suggestions as advisory, not a confirmed diagnosis.";
        $makeBody = static fn (array $genCfg) => [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => $genCfg,
        ];

        // No query-string key, request logging, caching, jobs, persisted prompt or retries.
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        try {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent";
            $timeout = max(10, min(45, (int) config('dtc_ai.timeout', 45)));

            $activeConfig = $generationConfig;
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout($timeout)
                ->post($url, $makeBody($activeConfig));

            if ($response->status() === 400 && isset($activeConfig['thinkingConfig'])) {
                unset($activeConfig['thinkingConfig']);
                $response = Http::withHeaders(['x-goog-api-key' => $key])
                    ->acceptJson()
                    ->connectTimeout(5)
                    ->timeout($timeout)
                    ->post($url, $makeBody($activeConfig));
            }

            if ($response->status() === 400 && (isset($activeConfig['responseSchema']) || isset($activeConfig['responseMimeType']))) {
                unset($activeConfig['responseSchema'], $activeConfig['responseMimeType']);
                $response = Http::withHeaders(['x-goog-api-key' => $key])
                    ->acceptJson()
                    ->connectTimeout(5)
                    ->timeout($timeout)
                    ->post($url, $makeBody($activeConfig));
            }

            if (!$response->successful() || strlen($response->body()) > 1048576) {
                $err = $response->json('0.error') ?? $response->json('error');
                Log::error('DTC AI provider HTTP error.', [
                    'status' => $response->status(),
                    'provider_status' => is_array($err) ? ($err['status'] ?? null) : null,
                    'provider_message' => is_array($err) ? ($err['message'] ?? null) : null,
                ]);
                throw new AiProviderUnavailable(AiProviderUnavailable::CAPACITY_MESSAGE, 502, 'GEMINI_CAPACITY_ISSUE');
            }

            $chunks = $response->json();
            if (!is_array($chunks)) {
                $chunks = [];
                foreach (preg_split('/\r?\n/', $response->body()) ?: [] as $line) {
                    $line = trim($line);
                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $payload = trim(substr($line, 5));
                    if ($payload === '' || $payload === '[DONE]') {
                        continue;
                    }
                    $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $chunks[] = $decoded;
                    }
                }
                if ($chunks === []) {
                    throw new AiProviderUnavailable(AiProviderUnavailable::CAPACITY_MESSAGE, 502, 'GEMINI_CAPACITY_ISSUE');
                }
            }

            if (!array_is_list($chunks)) {
                $chunks = [$chunks];
            }

            $text = '';
            foreach ($chunks as $chunk) {
                foreach ($chunk['candidates'][0]['content']['parts'] ?? [] as $part) {
                    if (!($part['thought'] ?? false) && is_string($part['text'] ?? null)) {
                        $text .= $part['text'];
                    }
                }
            }

            $data = $this->decodeModelJson($text);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('DTC AI provider unavailable or incompatible.', ['exception' => get_class($e)]);
            throw new AiProviderUnavailable(AiProviderUnavailable::CAPACITY_MESSAGE, 502, 'GEMINI_CAPACITY_ISSUE');
        }

        if (!is_array($data)) {
            Log::error('DTC AI provider returned malformed JSON structure.');
            throw new AiProviderUnavailable(AiProviderUnavailable::CAPACITY_MESSAGE, 502, 'GEMINI_CAPACITY_ISSUE');
        }

        $projected = [
            'code' => strtoupper(trim((string) ($data['code'] ?? ''))),
            'description' => is_string($data['description'] ?? null) ? trim($data['description']) : null,
            'component' => strtoupper(trim((string) ($data['component'] ?? ''))),
            'possible_solution' => is_string($data['possible_solution'] ?? null) ? trim($data['possible_solution']) : null,
        ];
        if ($projected['component'] === 'TCU') {
            $projected['component'] = 'TCM';
        }

        if (
            $projected['code'] !== $code
            || !in_array($projected['component'], ['TCM', 'ECU', 'OBD'], true)
            || !is_string($projected['description'])
            || !is_string($projected['possible_solution'])
        ) {
            Log::error('DTC AI provider returned malformed JSON fields.');
            throw new AiProviderUnavailable(AiProviderUnavailable::CAPACITY_MESSAGE, 502, 'GEMINI_CAPACITY_ISSUE');
        }

        $words = fn (string $s): int => count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY));
        if (
            !(
                strlen($projected['description']) <= 4000
                && strlen($projected['possible_solution']) <= 2000
                && $words($projected['description']) >= 3
                && $words($projected['description']) <= 80
                && $words($projected['possible_solution']) >= 8
                && $words($projected['possible_solution']) <= 60
            )
        ) {
            Log::error('DTC AI provider output violated word limits.');
            throw new AiProviderUnavailable(AiProviderUnavailable::CAPACITY_MESSAGE, 502, 'GEMINI_CAPACITY_ISSUE');
        }

        return $projected;
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
