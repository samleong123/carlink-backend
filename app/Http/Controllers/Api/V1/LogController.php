<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AuditAi;
use App\Services\Permissions;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LogController
{
    private const SECRET_CONTEXT_PATTERN = '/token|secret|password|cookie|authorization|credential|auth_token|signature|body|payload|raw_payload|content_base64|dtc|possible_solution|payment_url|callback|client_hints|proxy_chain|idempotency_key_hash/i';

    public function requests(Request $request, string $organization)
    {
        return $this->index($request, $organization, false);
    }

    public function actions(Request $request, string $organization)
    {
        return $this->index($request, $organization, true);
    }

    public function explainActions(Request $request, string $organization)
    {
        abort_unless(app(Permissions::class)->allows($request->attributes->get('membership'), 'audit.read'), 403);
        $validated = $request->validate([
            'audit_ids' => 'required|array|min:1|max:20',
            'audit_ids.*' => 'required|uuid|distinct',
        ]);

        try {
            $rows = DB::connection('logging')
                ->table('audit_logs')
                ->where('organization_id', $organization)
                ->whereIn('id', $validated['audit_ids'])
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get()
                ->all();

            if ($rows === []) {
                abort(404, 'No matching audit records found.');
            }

            $records = $this->formatAuditLogs($rows);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponse::error($request, 'Logs Unavailable', 'The logging database is temporarily unavailable.', 503);
        }

        $analysis = app(AuditAi::class)->explain($records);

        return ApiResponse::success(
            $request,
            $analysis,
            'Action Audit AI Analysis',
            'Advisory AI explanation powered by Google Gemini. Not stored by CarLink.',
        );
    }

    private function index(Request $request, string $organization, bool $actions)
    {
        abort_unless(app(Permissions::class)->allows($request->attributes->get('membership'), $actions ? 'audit.read' : 'request_logs.read'), 403);
        $filter = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after:from',
            'per_page' => 'nullable|integer|min:1|max:200',
            'cursor' => 'nullable|string|max:512',
        ]);
        $from = CarbonImmutable::parse($filter['from'])->utc();
        $to = CarbonImmutable::parse($filter['to'])->utc();
        if ($from->diffInDays($to) > 31) {
            throw ValidationException::withMessages(['to' => ['Log queries are limited to 31 days.']]);
        }

        $time = $actions ? 'occurred_at' : 'started_at';
        $id = $actions ? 'id' : 'request_id';
        try {
            $page = DB::connection('logging')
                ->table($actions ? 'audit_logs' : 'http_request_logs')
                ->where('organization_id', $organization)
                ->where($time, '>=', $from)
                ->where($time, '<', $to)
                ->orderBy($time)
                ->orderBy($id)
                ->cursorPaginate($filter['per_page'] ?? 50);

            $rows = $page->items();
            $items = $actions
                ? $this->formatAuditLogs($rows)
                : array_map(fn ($row) => self::formatRequestLog($row), $rows);
        } catch (\Throwable $e) {
            return ApiResponse::error($request, 'Logs Unavailable', 'The logging database is temporarily unavailable.', 503);
        }

        return ApiResponse::success(
            $request,
            [
                'items' => $items,
                'pagination' => [
                    'per_page' => $page->perPage(),
                    'next_cursor' => $page->nextCursor()?->encode(),
                ],
                'links' => ['next' => $page->nextPageUrl()],
            ],
            'Logs Retrieved',
        );
    }

    public static function formatRequestLog(object $row): array
    {
        $ctx = self::decodeContext($row->context ?? null);
        $route = self::firstNonEmptyString([$row->route ?? null, $ctx['route'] ?? null]);
        $httpStatus = self::firstInt([$row->http_status ?? null, $ctx['status'] ?? null, $ctx['http_status'] ?? null]);
        $durationMs = self::firstInt([$row->duration_ms ?? null, $ctx['duration_ms'] ?? null]);
        $ipAddress = self::firstNonEmptyString([$ctx['ip_address'] ?? null, $ctx['peer_ip'] ?? null]);
        $ipCountry = self::firstNonEmptyString([$ctx['ip_country'] ?? null]);
        $browser = self::formatBrowser($ctx);
        $userAgent = self::firstNonEmptyString([$ctx['user_agent_raw'] ?? null, $ctx['user_agent'] ?? null]) ?? $browser;

        return [
            'request_id' => $row->request_id,
            'started_at' => $row->started_at ?? null,
            'completed_at' => $row->completed_at ?? null,
            'state' => $row->state ?? null,
            'organization_id' => $row->organization_id ?? null,
            'user_id' => $row->user_id ?? null,
            'method' => self::firstNonEmptyString([$row->method ?? null, $ctx['method'] ?? null]),
            'route' => $route,
            'route_template' => $route,
            'http_status' => $httpStatus,
            'status' => $httpStatus,
            'duration_ms' => $durationMs,
            'ip_address' => $ipAddress,
            'ip_country' => $ipCountry,
            'user_agent' => $userAgent,
            'browser' => $browser,
        ];
    }

    private function formatAuditLogs(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $decoded = [];
        $userIds = [];
        $requestIds = [];

        foreach ($rows as $idx => $row) {
            $ctx = self::decodeContext($row->context ?? null);
            $decoded[$idx] = $ctx;

            $userId = self::firstNonEmptyString([$row->user_id ?? null, $ctx['user_id'] ?? null]);
            if ($userId !== null) {
                $userIds[$userId] = true;
            }

            $corrId = self::firstNonEmptyString([$row->request_id ?? null, $row->origin_request_id ?? null]);
            if ($corrId !== null) {
                $requestIds[$corrId] = true;
            }
        }

        $requestLogs = $requestIds !== []
            ? DB::connection('logging')
                ->table('http_request_logs')
                ->whereIn('request_id', array_keys($requestIds))
                ->get()
                ->keyBy('request_id')
            : collect();

        $users = $userIds !== []
            ? DB::table('users')
                ->whereIn('id', array_keys($userIds))
                ->select(['id', 'display_name', 'email_normalized'])
                ->get()
                ->keyBy('id')
            : collect();

        $items = [];
        foreach ($rows as $idx => $row) {
            $ctx = $decoded[$idx];
            $userId = self::firstNonEmptyString([$row->user_id ?? null, $ctx['user_id'] ?? null]);
            $user = $userId !== null ? $users->get($userId) : null;
            $source = self::firstNonEmptyString([$row->source ?? null]) ?? 'worker';

            if ($user) {
                $displayName = trim((string) ($user->display_name ?? ''));
                $email = trim((string) ($user->email_normalized ?? ''));
                $actorName = $displayName !== ''
                    ? ($email !== '' ? "{$displayName} ({$email})" : $displayName)
                    : ($email !== '' ? $email : "user ({$userId})");
            } elseif ($userId !== null) {
                $actorName = "user ({$userId})";
            } else {
                $actorName = "system ({$source})";
            }

            $requestId = self::firstNonEmptyString([$row->request_id ?? null, $row->origin_request_id ?? null]);
            $reqLog = $requestId !== null ? $requestLogs->get($requestId) : null;
            $reqCtx = $reqLog ? self::decodeContext($reqLog->context ?? null) : [];

            $method = self::firstNonEmptyString([$ctx['method'] ?? null, $reqLog->method ?? null, $reqCtx['method'] ?? null]);
            $route = self::firstNonEmptyString([$ctx['route'] ?? null, $reqLog->route ?? null, $reqCtx['route'] ?? null]);
            $httpStatus = self::firstInt([$ctx['status'] ?? null, $ctx['http_status'] ?? null, $reqLog->http_status ?? null, $reqCtx['status'] ?? null]);
            $ipAddress = self::firstNonEmptyString([$ctx['ip_address'] ?? null, $ctx['peer_ip'] ?? null, $reqCtx['ip_address'] ?? null, $reqCtx['peer_ip'] ?? null]);
            $ipCountry = self::firstNonEmptyString([$ctx['ip_country'] ?? null, $reqCtx['ip_country'] ?? null]);
            $browser = self::formatBrowser($ctx) ?? self::formatBrowser($reqCtx);
            $userAgent = self::firstNonEmptyString([$ctx['user_agent_raw'] ?? null, $ctx['user_agent'] ?? null, $reqCtx['user_agent_raw'] ?? null]) ?? $browser;

            $items[] = [
                'id' => $row->id,
                'occurred_at' => $row->occurred_at,
                'organization_id' => $row->organization_id ?? null,
                'user_id' => $userId,
                'actor_user_id' => $userId,
                'actor_name' => $actorName,
                'action_name' => $row->action_name,
                'action' => $row->action_name,
                'outcome' => $row->outcome,
                'source' => $source,
                'request_id' => $requestId,
                'origin_request_id' => $row->origin_request_id ?? null,
                'method' => $method,
                'route' => $route,
                'http_status' => $httpStatus,
                'status' => $httpStatus,
                'ip_address' => $ipAddress,
                'ip_country' => $ipCountry,
                'user_agent' => $userAgent,
                'browser' => $browser,
                'context' => (object) self::sanitizeContext($ctx),
            ];
        }

        return $items;
    }

    private static function decodeContext(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_object($raw)) {
            return (array) $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private static function sanitizeContext(array $ctx): array
    {
        $safe = [];
        foreach ($ctx as $k => $v) {
            if (!is_string($k) || preg_match(self::SECRET_CONTEXT_PATTERN, $k)) {
                continue;
            }
            if (is_array($v)) {
                $nested = self::sanitizeContext($v);
                if ($nested !== []) {
                    $safe[$k] = $nested;
                }
            } elseif (is_scalar($v) || $v === null) {
                $safe[$k] = is_string($v) ? substr($v, 0, 512) : $v;
            }
        }
        return $safe;
    }

    private static function formatBrowser(array $ctx): ?string
    {
        $name = is_string($ctx['browser_name'] ?? null) ? trim($ctx['browser_name']) : '';
        $version = is_string($ctx['browser_version'] ?? null) ? trim($ctx['browser_version']) : '';
        $platform = is_string($ctx['platform_name'] ?? null) ? trim($ctx['platform_name']) : '';
        $browserPart = trim($name . ($version !== '' ? ' ' . $version : ''));
        if ($browserPart !== '' && $platform !== '') {
            return $browserPart . ' · ' . $platform;
        }
        if ($browserPart !== '') {
            return $browserPart;
        }
        return $platform !== '' ? $platform : null;
    }

    private static function firstNonEmptyString(array $candidates): ?string
    {
        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }
        return null;
    }

    private static function firstInt(array $candidates): ?int
    {
        foreach ($candidates as $value) {
            if ($value !== null && $value !== '' && is_numeric($value)) {
                return (int) $value;
            }
        }
        return null;
    }
}
