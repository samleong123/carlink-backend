<?php

namespace App\Http\Middleware;

use App\Services\ActionAudit;
use App\Services\RequestLogWriter;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Route action coverage; pure database mutations gain atomic audit responsibility. */
class AuditAction
{
    public function handle(Request $request, Closure $next): mixed
    {
        $audit = app(ActionAudit::class); $writer = app(RequestLogWriter::class);
        $request->attributes->set('action_audit_started', true);
        $action = $request->method().' '.($request->route()?->uri() ?? 'unmatched');
        if (!$writer->action($audit->event($action, 'attempted', 'http', $audit->context($request)))) return ApiResponse::error($request, 'Logging Unavailable', 'Action logging is unavailable.', 503);
        // Do not hold a database transaction around gateway/SES/session side effects.
        $controller = $request->route()?->getActionName() ?? '';
        $transactional = !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && preg_match('/(?:Fleet|Maintenance|Compliance|Mileage|DriverShift|TenantAdministration|Invitation|Platform|DeviceManagement|Safety|DeviceCommand|DriverSelf)Controller@/', $controller)
            && !str_ends_with($controller,'@commissioning');
        $level = DB::transactionLevel();
        if ($transactional) DB::beginTransaction();
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $outcome = $status < 400 ? 'succeeded' : (in_array($status, [401, 403, 404], true) ? 'denied' : 'failed');
            $event = $audit->event($action, $outcome, 'http', $audit->context($request) + ['status' => $status]);
            if ($transactional && $status < 400) { $audit->outbox($event); DB::commit(); }
            else {
                if ($transactional) DB::rollBack();
                if (!$writer->action($event)) return ApiResponse::error($request, 'Logging Unavailable', 'Reconcile the action outcome before retrying.', 503);
            }
            return $response;
        } catch (\Throwable $e) {
            while (DB::transactionLevel() > $level) DB::rollBack();
            $writer->action($audit->event($action, 'failed', 'http', $audit->context($request) + ['error_type' => class_basename($e)]));
            throw $e;
        }
    }
}
