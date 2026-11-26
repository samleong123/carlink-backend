<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('payments:expire')->everyMinute()->withoutOverlapping();
Schedule::command('signups:delete-unpaid')->everyMinute()->withoutOverlapping();
Schedule::command('logs:flush-request-spool')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('logs:deliver-audit-outbox')->everyMinute()->withoutOverlapping();
Schedule::command('driver-shifts:expire')->everyMinute()->withoutOverlapping();
Schedule::command('journeys:mark-gaps')->everyMinute()->withoutOverlapping();
Schedule::command('compliance:evaluate')->everyMinute()->withoutOverlapping();
Schedule::command('telemetry:project-pending')->everyMinute()->withoutOverlapping();
Schedule::command('maintenance:evaluate')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('incidents:evaluate-connectivity')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('mail:dispatch-pending')->everyMinute()->withoutOverlapping();
Schedule::command('mail:generate-notifications')->hourly()->withoutOverlapping();
Schedule::command('privacy:retain-driver-history')->daily()->withoutOverlapping();
Schedule::command('files:cleanup --limit=100')->everyFiveMinutes()->withoutOverlapping();
Schedule::call(fn()=>\Illuminate\Support\Facades\DB::table('device_commands')->whereIn('state',['pending','running'])->where('expires_at','<=',now())->update(['state'=>'expired','updated_at'=>now()]))->name('device-commands:expire')->everyMinute()->withoutOverlapping();
if (config('broker.verified_topic_acls')) {
    Schedule::command('outbox:publish --limit=200')->everyFiveSeconds()->withoutOverlapping(5);
    Schedule::command('device-events:publish --limit=200')->everyMinute()->withoutOverlapping();
}
