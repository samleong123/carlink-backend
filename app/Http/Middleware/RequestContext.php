<?php
namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Services\RequestLogWriter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Contracts\Debug\ExceptionHandler;
use App\Services\TrustedClientIpResolver;
use App\Services\BrowserMetadataResolver;
use App\Services\ActionAudit;

class RequestContext
{
    public function __construct(private readonly RequestLogWriter $writer) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $request->attributes->set('request_id', (string) Str::uuid());
        $identity = array_merge(app(TrustedClientIpResolver::class)->resolve($request), app(BrowserMetadataResolver::class)->resolve($request));
        $identity['client_request_id'] = substr(preg_replace('/[\x00-\x1f\x7f]/', '', (string) $request->header('X-Request-ID')), 0, 128);
        $request->attributes->set('identity_context', $identity);
        $request->headers->set('Accept', 'application/json');
        $id = $request->attributes->get('request_id');
        $started = microtime(true);
        if (!$this->writer->write($id, 'started', ['method'=>$request->method()] + $identity)) {
            return ApiResponse::error($request, 'Logging Unavailable', 'The request cannot be processed at this time.', 503);
        }
        try {
            $response = $this->process($request, $next);
        } catch (\Throwable $error) {
            // Render at the outer boundary so completion records the actual error envelope.
            $response = app(ExceptionHandler::class)->render($request, $error);
        }
        $context = app(ActionAudit::class)->context($request);
        if (!$request->attributes->get('action_audit_started')) {
            $audit = app(ActionAudit::class);
            $action = $request->method().' '.($request->route()?->uri() ?? 'unmatched');
            $status = $response->getStatusCode();
            $attempted = $this->writer->action($audit->event($action,'attempted','http',$context));
            $completed = $this->writer->action($audit->event($action,$status < 400 ? 'succeeded' : (in_array($status,[401,403,404],true) ? 'denied' : 'failed'),'http',$context + ['status'=>$status]));
            if (!$attempted || !$completed) $response = ApiResponse::error($request,'Logging Unavailable','Action logging failed. Reconcile the outcome before retrying.',503);
        }
        $body = $response instanceof \Illuminate\Http\JsonResponse ? $response->getData(true) : [];
        if (!$this->writer->write($id, 'completed', $context + ['status'=>$response->getStatusCode(), 'duration_ms'=>(int) ((microtime(true)-$started)*1000), 'title'=>$body['title'] ?? null, 'desc'=>$body['desc'] ?? null, 'request_bytes'=>strlen($request->getContent()), 'response_bytes'=>strlen($response->getContent())])) {
            // The operation may already have committed; the caller must reconcile before retrying.
            return ApiResponse::error($request, 'Logging Unavailable', 'The request outcome could not be recorded. Reconcile before retrying.', 503);
        }
        $response->headers->set('X-Request-ID', $id);
        $response->headers->set('Cache-Control', 'private, no-store');
        if ($request->isSecure()) $response->headers->set('Accept-CH', 'Sec-CH-UA-Full-Version-List');
        return $response;
    }

    private function process(Request $request, Closure $next): mixed
    {
        $upload=$request->isMethod('POST') && preg_match('#^api/v1/organizations/[^/]+/(?:files|drivers/[^/]+/photo)$#D',$request->path());
        if ($upload && !$request->isJson()) return ApiResponse::error($request,'Unsupported Media Type','File uploads must use server-mediated application/json base64.',415);
        if ($upload && strlen($request->getContent())>15728640) return ApiResponse::error($request,'Payload Too Large','Upload JSON must not exceed 15 MiB.',413);
        // The payment provider only promises an HTTP POST, not a JSON payload.
        // Its callback route uses a one-time bearer URL and ignores the body.
        // Other API requests retain the JSON-only input contract.
        if ($request->isMethod('POST') && preg_match('#^api/v1/payment-gateway/callback/[^/]+/[^/]+$#D', $request->path())) {
            return $next($request);
        }
        if (in_array($request->method(), ['POST','PUT','PATCH'], true) && $request->getContent() !== '' && !$request->isJson()) {
            return ApiResponse::error($request, 'Unsupported Media Type', 'Request bodies must use application/json.', 415);
        }
        if ($request->isJson() && $request->getContent() !== '') {
            json_decode($request->getContent());
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ApiResponse::error($request, 'Invalid JSON', 'Request body must be valid JSON.', 400);
            }
        }
        return $next($request);
    }
}
