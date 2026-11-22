<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\{DriverEcoCoachAi, Entitlements, FleetCarbonAi, Permissions, PlatformHealthTriageAi};
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiInsightsController
{
    public function fleetCarbon(Request $r, string $organization)
    {
        abort_unless(app(Permissions::class)->allows($r->attributes->get('membership'), 'reports.read'), 403);
        if (config('saas.enforce_subscriptions')) {
            app(Entitlements::class)->requireActive($organization);
        }

        $v = $r->validate([
            'days' => 'nullable|integer|min:1|max:90',
        ]);
        $days = (int) ($v['days'] ?? 30);

        $analysis = app(FleetCarbonAi::class)->analyze($organization, $days);

        return ApiResponse::success(
            $r,
            $analysis,
            'Fleet Carbon AI Analysis',
            'Advisory AI output powered by Google Gemini. Not stored by CarLink.',
        );
    }

    public function driverEcoCoach(Request $r, string $organization)
    {
        abort_unless(app(Permissions::class)->allows($r->attributes->get('membership'), 'profile.own'), 403);
        $driver = DB::table('drivers')
            ->where('organization_id', $organization)
            ->where('user_id', $r->user()->id)
            ->where('status', 'active')
            ->first() ?? abort(404);

        if (config('saas.enforce_subscriptions')) {
            app(Entitlements::class)->requireActive($organization);
        }

        $v = $r->validate([
            'days' => 'nullable|integer|min:1|max:90',
        ]);
        $days = (int) ($v['days'] ?? 14);

        $coaching = app(DriverEcoCoachAi::class)->coach($organization, $driver->id, $days);

        return ApiResponse::success(
            $r,
            $coaching,
            'Driver Eco-Coach AI Analysis',
            'Advisory AI coaching powered by Google Gemini. Not stored by CarLink.',
        );
    }

    public function platformHealthTriage(Request $r)
    {
        $v = $r->validate([
            'days' => 'nullable|integer|min:1|max:30',
        ]);
        $days = (int) ($v['days'] ?? 7);

        $triage = app(PlatformHealthTriageAi::class)->triage($days);

        return ApiResponse::success(
            $r,
            $triage,
            'Platform Health Triage AI Brief',
            'Advisory AI operations brief powered by Google Gemini. Not stored by CarLink.',
        );
    }
}
