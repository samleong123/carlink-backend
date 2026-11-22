<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Global templates; grants are resolved against an active tenant membership. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $read = ['organizations.read', 'settings.read', 'vehicles.read', 'drivers.read', 'devices.read', 'shifts.read', 'journeys.read', 'mileage.read', 'maintenance.read', 'compliance.read', 'safety.read', 'incidents.read', 'analytics.read', 'routes.read', 'coverage.read', 'dashboard.read', 'telemetry.read', 'reports.read'];
        $operations = ['vehicles.write', 'drivers.write', 'devices.provision', 'devices.write', 'device_config.publish', 'shifts.manage', 'credentials.manage', 'journeys.rebuild', 'mileage.correct', 'maintenance.write', 'maintenance.complete', 'compliance.write', 'compliance.verify', 'incidents.write', 'alerts.manage', 'reports.export', 'routes.write', 'diagnostics.run'];
        $admin = ['organizations.write', 'settings.write', 'members.read', 'members.manage', 'subscription.read', 'usage.read', 'reports.manage', 'analytics.configure', 'analytics.recalculate', 'coverage.export', 'audit.read', 'request_logs.read', 'files.manage', 'governance.manage'];
        $safety = ['safety.configure', 'safety.review', 'safety.appeals.decide'];
        $own = ['notifications.own', 'profile.own', 'shifts.own', 'journeys.own', 'mileage.own', 'safety.own', 'appeals.own', 'incidents.report_own', 'reports.export_own', 'dashboard.own'];
        $platform = ['platform.organizations.manage', 'platform.plans.manage', 'platform.support.grant', 'platform.operations.read', 'platform.request_logs.read'];
        $roles = [
            'owner' => array_merge($read, $operations, $admin, $safety, $own, ['ownership.transfer','payments.manage']),
            'admin' => array_merge($read, $operations, $admin, $safety, $own, ['payments.manage']),
            'manager' => array_merge($read, $operations, ['members.read', 'notifications.own', 'profile.own']),
            'viewer' => array_merge($read, ['notifications.own', 'profile.own']),
            'safety_officer' => array_merge(['vehicles.read', 'drivers.read', 'shifts.read', 'journeys.read', 'safety.read', 'incidents.read', 'incidents.write', 'reports.read', 'reports.export', 'notifications.own', 'profile.own'], $safety),
            'maintenance_officer' => ['vehicles.read', 'mileage.read', 'maintenance.read', 'maintenance.write', 'maintenance.complete', 'compliance.read', 'compliance.write', 'compliance.verify', 'reports.read', 'reports.export', 'notifications.own', 'profile.own'],
            'driver' => $own,
            // A tenant support role does NOT confer platform or location permissions.
            'support' => ['organizations.read', 'settings.read'],
            'module_vehicle' => ['vehicles.read','vehicles.write','devices.read','telemetry.read','mileage.read','maintenance.read','maintenance.write','maintenance.complete','compliance.read','compliance.write','diagnostics.run','device_config.publish'],
            'module_driver' => ['drivers.read','drivers.write','shifts.read','safety.read'],
            'module_dashboard' => ['dashboard.read','telemetry.read','vehicles.read'],
            'module_compliance' => ['vehicles.read','compliance.read','compliance.write','compliance.verify','maintenance.read','mileage.read','safety.read','safety.appeals.decide'],
            'module_coverage' => ['vehicles.read','coverage.read','coverage.export'],
        ];
        DB::transaction(function () use ($roles, $platform) {
            foreach (array_unique(array_merge($platform, ...array_values($roles))) as $key) DB::table('permissions')->insertOrIgnore(['key' => $key]);
            foreach ($roles as $code => $keys) {
                DB::table('roles')->insertOrIgnore(['code' => $code]);
                foreach (array_unique($keys) as $key) DB::table('role_permissions')->insertOrIgnore(['role_code' => $code, 'permission_key' => $key]);
            }
        });
    }
}
