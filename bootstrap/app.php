<?php

use App\Http\Middleware\RequestContext;
use App\Http\Middleware\Tenant;
use App\Http\Middleware\ExpireAbsoluteSession;
use App\Support\ApiResponse;
use App\Exceptions\FileDependencyUnavailable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(append: [ExpireAbsoluteSession::class, \App\Http\Middleware\AuditAction::class]);
        $middleware->prependToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, ExpireAbsoluteSession::class);
        $middleware->prepend(RequestContext::class);
        $middleware->alias(['tenant' => Tenant::class, 'platform' => \App\Http\Middleware\Platform::class,'device-token'=>\App\Http\Middleware\DeviceToken::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => true);
        $exceptions->render(function (Throwable $e, Request $request) {
            $status = match (true) {
                $e instanceof ValidationException => 422,
                $e instanceof AuthenticationException => 401,
                $e instanceof AuthorizationException => 403,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };
            $message = match ($status) { 401 => 'Authentication required.', 403 => 'Permission denied.', 404 => 'Resource not found.', 422 => 'The supplied data is invalid.', 429 => 'Too many requests.', default => $status >= 500 ? 'An internal error occurred.' : 'The request could not be completed.' };
            $codes = [400=>'MALFORMED_REQUEST',401=>'UNAUTHENTICATED',403=>'FORBIDDEN',404=>'NOT_FOUND',405=>'METHOD_NOT_ALLOWED',409=>'STATE_CONFLICT',413=>'PAYLOAD_TOO_LARGE',415=>'UNSUPPORTED_MEDIA_TYPE',419=>'CSRF_FAILED',422=>'VALIDATION_FAILED',429=>'THROTTLED',500=>'INTERNAL_ERROR',503=>'UNAVAILABLE'];
            $data = ['error'=>['code'=>$codes[$status] ?? 'REQUEST_FAILED']];
            if ($e instanceof FileDependencyUnavailable) {
                $message=$e->getMessage(); $data['error']['code']=$e->reasonCode;
                // Ordinary HttpException reporting omits 503s; record only bounded, secret-safe diagnostics.
                try { \Illuminate\Support\Facades\Log::warning('Private file dependency unavailable',['request_id'=>$request->attributes->get('request_id')]+$e->diagnosticContext()); }
                catch (\Throwable $loggingError) { /* Do not replace the original dependency failure. */ }
            }
            if ($e instanceof \App\Exceptions\AiProviderUnavailable) {
                $message = $e->getMessage();
                $data['error']['code'] = $e->reasonCode;
            }
            if ($e instanceof ValidationException) { $data['error']['fields'] = $e->errors(); $data['errors'] = $e->errors(); }
            $response = ApiResponse::error($request, 'Request Failed', $message, $status, $data);
            if ($e instanceof HttpExceptionInterface) foreach ($e->getHeaders() as $name => $value) $response->headers->set($name, $value);
            return $response;
        });
    })->create();
