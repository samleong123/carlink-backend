<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PlatformHealthTriageAi
{
    private const ALLOWED_SEVERITIES = ['critical', 'high', 'medium', 'low'];
    private const ALLOWED_AREAS = ['devices', 'api_errors', 'subscriptions', 'alerts', 'telemetry'];

    public function __construct(
        private readonly GeminiJsonClient $gemini,
    ) {
    }

    /**
     * Generate a transient, strictly PII-scrubbed platform operations triage brief.
     */
    public function triage(int $days = 7): array
    {
        $days = max(1, min(30, $days));
        $now = CarbonImmutable::now('UTC');
        $from = $now->subDays($days);

        $orgSlugs = DB::table('organizations')->pluck('slug', 'id')->all();

        [$offlineDevicesCount, $pendingDevicesCount, $deviceSignals] = $this->collectDeviceSignals($now, $orgSlugs);
        [$expiringSubscriptionsCount, $unpaidSignupsCount, $subscriptionSignals] = $this->collectSubscriptionAndBillingSignals($now, $orgSlugs);
        [$elevatedErrorOrgsCount, $elevatedErrorOrgs, $loggingAvailable] = $this->collectElevatedErrorOrgs($from, $orgSlugs);
        [$recentAlertsCount, $alertSpikes] = $this->collectAlertSpikes($from, $now);

        $platformSignals = [
            'offline_devices' => $offlineDevicesCount,
            'pending_devices' => $pendingDevicesCount,
            'expiring_subscriptions' => $expiringSubscriptionsCount,
            'unpaid_signups' => $unpaidSignupsCount,
            'elevated_error_orgs' => $elevatedErrorOrgsCount,
            'recent_alerts' => $recentAlertsCount,
        ];

        $promptContext = [
            'period_days' => $days,
            'logging_db_available' => $loggingAvailable,
            'platform_signals' => $platformSignals,
            'device_issues_sample' => $deviceSignals,
            'elevated_error_orgs' => $elevatedErrorOrgs,
            'subscription_and_billing_signals' => $subscriptionSignals,
            'alert_spikes' => $alertSpikes,
        ];

        $prompt = implode("\n", [
            'You are a SaaS fleet platform reliability and operations triage analyst.',
            'Analyze the following strictly PII-free platform-wide device health, API error rate, subscription/signup, and alert spike aggregates.',
            'If all signal counts are zero or datasets are empty, state clearly that no active operational anomalies were observed in the period and return an empty or low-severity routine check queue.',
            'Return ONLY a valid JSON object (no markdown fences or commentary outside JSON) with this exact structure:',
            '{"executive_brief":"string","priority_queue":[{"severity":"critical|high|medium|low","area":"devices|api_errors|subscriptions|alerts|telemetry","target":"string","reason":"string","next_action":"string"}]}',
            'Platform aggregates JSON:',
            json_encode($promptContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $ai = $this->gemini->generateJson($prompt, 'Platform Health Triage AI', 2048);
        $decoded = $ai['data'];

        if (!is_string($decoded['executive_brief'] ?? null) || trim($decoded['executive_brief']) === '') {
            abort(502, 'Platform Health Triage AI provider returned malformed analysis.');
        }

        $queue = [];
        foreach (is_array($decoded['priority_queue'] ?? null) ? $decoded['priority_queue'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $target = trim((string) ($item['target'] ?? ''));
            $reason = trim((string) ($item['reason'] ?? ''));
            if ($target === '' || $reason === '') {
                continue;
            }
            $severity = strtolower(trim((string) ($item['severity'] ?? 'medium')));
            if (!in_array($severity, self::ALLOWED_SEVERITIES, true)) {
                $severity = 'medium';
            }
            $area = strtolower(trim((string) ($item['area'] ?? 'telemetry')));
            if (!in_array($area, self::ALLOWED_AREAS, true)) {
                $area = 'telemetry';
            }
            $queue[] = [
                'severity' => $severity,
                'area' => $area,
                'target' => $this->gemini->scrubText($target, 120),
                'reason' => $this->gemini->scrubText($reason, 300),
                'next_action' => $this->gemini->scrubText(trim((string) ($item['next_action'] ?? '')), 300),
            ];
        }

        return [
            'model' => $ai['model'],
            'period_days' => $days,
            'advisory_only' => true,
            'platform_signals' => $platformSignals,
            'executive_brief' => trim($decoded['executive_brief']),
            'priority_queue' => $queue,
        ];
    }

    /**
     * @param array<string,string> $orgSlugs
     * @return array{0:int,1:int,2:list<array<string,mixed>>}
     */
    private function collectDeviceSignals(CarbonImmutable $now, array $orgSlugs): array
    {
        $offlineThresholds = [];
        foreach (DB::table('organization_settings')->get(['organization_id', 'settings']) as $row) {
            $decoded = $row->settings ? json_decode($row->settings, true) : null;
            $offlineThresholds[$row->organization_id] = max(90, min(3600, (int) ($decoded['device_offline_after_s'] ?? 180)));
        }

        $latestByDevice = DB::table('telemetry_samples as s')
            ->join('telemetry_inbox as i', 'i.id', '=', 's.inbox_id')
            ->select('i.device_id', DB::raw('MAX(s.received_at) as last_seen_at'))
            ->groupBy('i.device_id')
            ->pluck('last_seen_at', 'i.device_id')
            ->all();

        $devices = DB::table('devices')
            ->whereNotIn('status', ['decommissioned', 'archived'])
            ->orderBy('created_at')
            ->get(['id', 'organization_id', 'status', 'registered_vehicle_id']);

        $offlineCount = 0;
        $pendingCount = 0;
        $sampleList = [];

        foreach ($devices as $device) {
            $orgSlug = $this->gemini->scrubText($orgSlugs[$device->organization_id] ?? 'unknown-org', 80);
            if ($device->status === 'registered') {
                $pendingCount++;
                if (count($sampleList) < 15) {
                    $sampleList[] = [
                        'device_id' => $device->id,
                        'org_slug' => $orgSlug,
                        'status' => 'pending_commissioning',
                        'linked_to_vehicle' => $device->registered_vehicle_id !== null,
                        'last_seen_at' => null,
                    ];
                }
                continue;
            }

            if ($device->status === 'active') {
                $thresholdSeconds = $offlineThresholds[$device->organization_id] ?? 180;
                $lastSeenRaw = $latestByDevice[$device->id] ?? null;
                $lastSeen = $lastSeenRaw ? CarbonImmutable::parse($lastSeenRaw)->utc() : null;
                $isOffline = $lastSeen === null || $lastSeen->lt($now->subSeconds($thresholdSeconds));

                if ($isOffline) {
                    $offlineCount++;
                    if (count($sampleList) < 15) {
                        $sampleList[] = [
                            'device_id' => $device->id,
                            'org_slug' => $orgSlug,
                            'status' => $lastSeen === null ? 'active_never_seen' : 'active_stale',
                            'linked_to_vehicle' => $device->registered_vehicle_id !== null,
                            'last_seen_at' => $lastSeen?->toIso8601String(),
                            'stale_minutes' => $lastSeen ? round($lastSeen->diffInSeconds($now) / 60.0, 1) : null,
                        ];
                    }
                }
            }
        }

        return [$offlineCount, $pendingCount, $sampleList];
    }

    /**
     * @param array<string,string> $orgSlugs
     * @return array{0:int,1:int,2:array<string,mixed>}
     */
    private function collectSubscriptionAndBillingSignals(CarbonImmutable $now, array $orgSlugs): array
    {
        $expiringCutoff = $now->addDays(14);

        $expiringRows = DB::table('organization_subscriptions as s')
            ->join('organizations as o', 'o.id', '=', 's.organization_id')
            ->where('s.status', 'active')
            ->whereNotNull('s.expires_at')
            ->where('s.expires_at', '>', $now)
            ->where('s.expires_at', '<=', $expiringCutoff)
            ->orderBy('s.expires_at')
            ->get(['o.slug', 's.expires_at']);

        $expiringCount = $expiringRows->count();
        $expiringList = $expiringRows->take(10)->map(fn ($r) => [
            'org_slug' => $this->gemini->scrubText($r->slug, 80),
            'expires_at' => CarbonImmutable::parse($r->expires_at)->utc()->toIso8601String(),
        ])->values()->all();

        $suspendedList = DB::table('organization_subscriptions as s')
            ->join('organizations as o', 'o.id', '=', 's.organization_id')
            ->where(function ($q) {
                $q->where('s.status', 'suspended')->orWhere('o.status', 'suspended');
            })
            ->orderBy('o.slug')
            ->limit(10)
            ->get(['o.slug', 'o.status as org_status', 's.status as sub_status'])
            ->map(fn ($r) => [
                'org_slug' => $this->gemini->scrubText($r->slug, 80),
                'org_status' => $r->org_status,
                'subscription_status' => $r->sub_status,
            ])
            ->values()
            ->all();

        $unpaidSignupsCount = DB::table('organization_signups')
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->count();

        $overdueSignupsCount = DB::table('organization_signups')
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->where('payment_due_at', '<', $now)
            ->count();

        $issuedInvoices = DB::table('billing_invoices as i')
            ->join('organizations as o', 'o.id', '=', 'i.organization_id')
            ->where('i.status', 'issued')
            ->orderBy('i.created_at')
            ->limit(10)
            ->get(['o.slug', 'i.amount_minor', 'i.currency', 'i.created_at'])
            ->map(fn ($r) => [
                'org_slug' => $this->gemini->scrubText($r->slug, 80),
                'amount_minor' => (int) $r->amount_minor,
                'currency' => $r->currency,
                'issued_at' => CarbonImmutable::parse($r->created_at)->utc()->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            $expiringCount,
            $unpaidSignupsCount,
            [
                'expiring_within_14d' => $expiringList,
                'suspended_orgs' => $suspendedList,
                'pending_unpaid_signups_total' => $unpaidSignupsCount,
                'overdue_unpaid_signups' => $overdueSignupsCount,
                'open_issued_invoices_sample' => $issuedInvoices,
            ],
        ];
    }

    /**
     * @param array<string,string> $orgSlugs
     * @return array{0:int,1:list<array<string,mixed>>,2:bool}
     */
    private function collectElevatedErrorOrgs(CarbonImmutable $from, array $orgSlugs): array
    {
        try {
            $rows = DB::connection('logging')
                ->table('http_request_logs')
                ->where('started_at', '>=', $from)
                ->whereNotNull('organization_id')
                ->select(
                    'organization_id',
                    DB::raw('COUNT(*) as total_requests'),
                    DB::raw('SUM(CASE WHEN http_status >= 400 AND http_status < 500 THEN 1 ELSE 0 END) as client_errors'),
                    DB::raw('SUM(CASE WHEN http_status >= 500 THEN 1 ELSE 0 END) as server_errors'),
                    DB::raw('SUM(CASE WHEN http_status >= 400 THEN 1 ELSE 0 END) as error_requests')
                )
                ->groupBy('organization_id')
                ->get();

            $elevated = [];
            foreach ($rows as $row) {
                $total = (int) $row->total_requests;
                $clientErr = (int) $row->client_errors;
                $serverErr = (int) $row->server_errors;
                $totalErr = (int) $row->error_requests;
                $rate = $total > 0 ? ($totalErr / $total) : 0.0;

                if ($serverErr >= 1 || $totalErr >= 5 || ($total >= 5 && $rate >= 0.1)) {
                    $elevated[] = [
                        'org_slug' => $this->gemini->scrubText($orgSlugs[$row->organization_id] ?? 'unknown-org', 80),
                        'total_requests' => $total,
                        'client_errors_4xx' => $clientErr,
                        'server_errors_5xx' => $serverErr,
                        'error_rate_pct' => round($rate * 100.0, 1),
                    ];
                }
            }

            usort($elevated, static function (array $a, array $b): int {
                return $b['server_errors_5xx'] <=> $a['server_errors_5xx']
                    ?: $b['error_rate_pct'] <=> $a['error_rate_pct']
                    ?: $b['client_errors_4xx'] <=> $a['client_errors_4xx'];
            });

            return [count($elevated), array_slice($elevated, 0, 10), true];
        } catch (\Throwable) {
            return [0, [], false];
        }
    }

    /**
     * @return array{0:int,1:list<array<string,mixed>>}
     */
    private function collectAlertSpikes(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $totalRecentAlerts = (int) DB::table('alert_occurrences')
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $now)
            ->where('state', '<>', 'superseded')
            ->count();

        $spikes = DB::table('alert_occurrences as a')
            ->join('organizations as o', 'o.id', '=', 'a.organization_id')
            ->where('a.occurred_at', '>=', $from)
            ->where('a.occurred_at', '<=', $now)
            ->where('a.state', '<>', 'superseded')
            ->select(
                'o.slug',
                'a.kind',
                'a.severity',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN a.state = 'open' THEN 1 ELSE 0 END) as open_count")
            )
            ->groupBy('a.organization_id', 'o.slug', 'a.kind', 'a.severity')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'org_slug' => $this->gemini->scrubText($r->slug, 80),
                'kind' => $this->gemini->scrubText($r->kind, 100),
                'severity' => $r->severity,
                'count' => (int) $r->total,
                'open_count' => (int) $r->open_count,
            ])
            ->values()
            ->all();

        return [$totalRecentAlerts, $spikes];
    }
}
