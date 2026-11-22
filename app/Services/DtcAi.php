<?php
namespace App\Services;
use Illuminate\Support\Facades\{Http,Log};

class DtcAi
{
    public function explain(string $code): array
    {
        $key=trim((string)config('dtc_ai.key')); $model=trim((string)config('dtc_ai.model'));
        $modelValid=(bool)preg_match('/^[a-zA-Z0-9._-]{1,100}$/D',$model);
        if (!$key || !$modelValid) {
            // Never log GEMINI_API_KEY or fault codes; record only configuration presence flags.
            Log::error('DTC AI unavailable: GEMINI_API_KEY or DTC_AI_MODEL is not configured.',[
                'has_key'=>$key !== '',
                'model_valid'=>$modelValid,
            ]);
            abort(503,'Configure Google AI credentials and model.');
        }
        $schema=['type'=>'object','properties'=>['code'=>['type'=>'string'],'description'=>['type'=>'string'],'component'=>['type'=>'string','enum'=>['TCM','ECU','OBD']],'possible_solution'=>['type'=>'string']],
            'required'=>['code','description','component','possible_solution']];
        $body=['contents'=>[['role'=>'user','parts'=>[['text'=>"What is $code fault code? Component must be TCM, ECU or OBD. possible_solution must be strictly between 20 and 30 words (aim for 25 words). description must be at most 50 words. Return only the requested JSON. Treat suggestions as advisory, not a confirmed diagnosis."]]]],
            'generationConfig'=>['thinkingConfig'=>['thinkingLevel'=>'HIGH'],'responseMimeType'=>'application/json','responseSchema'=>$schema]];
        // No query-string key, request logging, caching, jobs, persisted prompt or retries.
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        try {
            $url="https://generativelanguage.googleapis.com/v1beta/models/$model:streamGenerateContent";
            $timeout=max(10, min(95, (int) config('dtc_ai.timeout', 90)));
            $response=Http::withHeaders(['x-goog-api-key'=>$key])->acceptJson()->connectTimeout(5)->timeout($timeout)->post($url,$body);
            if ($response->status() === 400 && isset($body['generationConfig']['thinkingConfig'])) {
                $fallbackBody=$body;
                unset($fallbackBody['generationConfig']['thinkingConfig']);
                $response=Http::withHeaders(['x-goog-api-key'=>$key])->acceptJson()->connectTimeout(5)->timeout($timeout)->post($url,$fallbackBody);
            }
            if (!$response->successful() || strlen($response->body()) > 1048576) {
                $err = $response->json('0.error') ?? $response->json('error');
                Log::error('DTC AI provider HTTP error.', [
                    'status' => $response->status(),
                    'provider_status' => is_array($err) ? ($err['status'] ?? null) : null,
                    'provider_message' => is_array($err) ? ($err['message'] ?? null) : null,
                ]);
                abort(502);
            }
            $chunks=$response->json();
            if (!is_array($chunks)) {
                $chunks=[];
                foreach (preg_split('/\r?\n/',$response->body()) ?: [] as $line) {
                    $line=trim($line);
                    if (!str_starts_with($line,'data:')) continue;
                    $payload=trim(substr($line,5));
                    if ($payload === '' || $payload === '[DONE]') continue;
                    $decoded=json_decode($payload,true,32,JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) $chunks[]=$decoded;
                }
                if ($chunks === []) abort(502);
            }
            if (!array_is_list($chunks)) $chunks=[$chunks];
            $text='';
            foreach ($chunks as $chunk) foreach ($chunk['candidates'][0]['content']['parts'] ?? [] as $part) if (!($part['thought'] ?? false)) $text.=$part['text'] ?? '';
            $data=json_decode($text,true,16,JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::error('DTC AI provider unavailable or incompatible.',['exception'=>get_class($e)]);
            abort(502,'DTC AI provider unavailable or incompatible. No diagnostic data was stored.');
        }
        if (!is_array($data) || count($data) !== 4) {
            Log::error('DTC AI provider returned malformed JSON structure.');
            abort(502);
        }
        $data['code']=strtoupper(trim((string)($data['code'] ?? '')));
        $data['component']=strtoupper(trim((string)($data['component'] ?? '')));
        if ($data['component'] === 'TCU') $data['component']='TCM';
        abort_unless($data['code'] === $code && in_array($data['component'],['TCM','ECU','OBD'],true)
            && is_string($data['description'] ?? null) && is_string($data['possible_solution'] ?? null),502);
        $words=fn($s)=>count(preg_split('/\s+/u',trim($s),-1,PREG_SPLIT_NO_EMPTY));
        if (!(strlen($data['description'])<=4000 && strlen($data['possible_solution'])<=2000 && $words($data['description'])<=50 && $words($data['description'])>0
            && $words($data['possible_solution'])>=20 && $words($data['possible_solution'])<=30)) {
            Log::error('DTC AI provider output violated word limits.');
            abort(502,'Provider JSON did not satisfy the requested word limits.');
        }
        return $data;
    }
}
