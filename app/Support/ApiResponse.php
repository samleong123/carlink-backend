<?php
namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ApiResponse
{
    public static function success(Request $request, mixed $data = [], string $title = 'Success', string $desc = '', int $status = 200): JsonResponse
    { return self::make($request, $data, $title, $desc, $status); }
    public static function error(Request $request, string $title, string $desc, int $status, mixed $data = []): JsonResponse
    {
        if (is_array($data) && !isset($data['error'])) $data['error'] = ['code'=>match ($status) {400=>'MALFORMED_REQUEST',401=>'UNAUTHENTICATED',403=>'FORBIDDEN',404=>'NOT_FOUND',409=>'STATE_CONFLICT',413=>'PAYLOAD_TOO_LARGE',415=>'UNSUPPORTED_MEDIA_TYPE',422=>'VALIDATION_FAILED',429=>'THROTTLED',503=>'UNAVAILABLE',default=>'REQUEST_FAILED'}];
        return self::make($request, $data, $title, $desc, $status);
    }
    private static function make(Request $request, mixed $data, string $title, string $desc, int $status): JsonResponse
    {
        $id = $request->attributes->get('request_id') ?? (string) Str::uuid();
        $payload = is_array($data) ? (object) $data : ($data ?? (object) []);
        $identity = $request->attributes->get('identity_context', []);
        $ip = $identity['ip_address'] ?? null;
        $country = $identity['ip_country'] ?? null;
        return response()->json([
            'status' => $status,
            'timestamp' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'ip_address' => $ip,
            'ip_country' => $country,
            'request_id' => $id,
            'title' => $title,
            'desc' => $desc,
            'data' => $payload,
        ], $status)->header('X-Request-ID', $id)->header('Cache-Control', 'private, no-store');
    }
}
