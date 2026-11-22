<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActionAudit
{
    public function context(Request $request): array
    {
        $membership = $request->attributes->get('membership');
        $template = $request->route()?->uri();
        return array_merge($request->attributes->get('identity_context', []), [
            'request_id' => $request->attributes->get('request_id'), 'user_id' => $request->user()?->id,
            'organization_id' => $membership?->organization_id, 'membership_id' => $membership?->id,
            'method' => $request->method(), 'route' => $template,
            // No raw URL, query, body, cookie, fingerprint, or credential is recorded.
            'idempotency_key_hash' => $request->hasHeader('Idempotency-Key') ? hash('sha256', substr($request->header('Idempotency-Key'), 0, 255)) : null,
        ]);
    }

    public function event(string $action, string $outcome, string $source, array $context): array
    {
        return ['id' => (string) Str::uuid(), 'record_type' => 'action', 'action_name' => substr($action, 0, 255),
            'outcome' => $outcome, 'source' => $source, 'occurred_at' => now('UTC')->toIso8601String(), 'context' => $context];
    }

    public function outbox(array $event): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Audit responsibility must commit with the business transaction.');
        DB::table('audit_outbox')->insert(['id' => $event['id'], 'organization_id' => $event['context']['organization_id'] ?? null, 'event' => json_encode($event, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
