<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class CheckDeployment extends Command
{
    protected $signature='carlink:check-deployment {--broker : Require broker and NFC configuration, not a live ACL test}';
    protected $description='Secret-safe production configuration and database/Redis connectivity checks';
    public function handle(): int
    {
        $checks=['production mode'=>config('app.env') === 'production','debug disabled'=>!config('app.debug'),
            'application key present'=>is_string(config('app.key')) && strlen(config('app.key'))>=32,
            'API HTTPS'=>str_starts_with(config('app.url'),'https://'),'frontend HTTPS'=>str_starts_with(config('app.frontend_url'),'https://'),
            'secure cookies'=>config('session.secure') === true,'Redis queues'=>config('queue.default') === 'redis','SES mail transport'=>config('mail.default') === 'ses',
            'subscription enforcement'=>config('saas.enforce_subscriptions') === true,'managed devices'=>config('saas.managed_devices') === true,
            'image decoder'=>extension_loaded('gd'),'content MIME detection'=>extension_loaded('fileinfo'),
            'private attachment disk'=>config('attachments.disk')==='s3'];
        foreach (['pgsql','logging'] as $connection) {
            try { DB::connection($connection)->select('select 1'); $checks[$connection.' connectivity']=true; }
            catch (\Throwable $e) { $checks[$connection.' connectivity']=false; }
        }
        try { Redis::connection()->ping(); $checks['Redis connectivity']=true; } catch (\Throwable $e) { $checks['Redis connectivity']=false; }
        foreach (['telemetry_samples','mail_outbox','organization_invitations','email_notifications','email_score_baselines','audit_outbox','saas_admins','subscription_plans','organization_subscriptions','billing_invoices','device_commands','safety_events','safety_appeals','private_assets','organization_signups','organization_signup_intents','signup_financial_archives','signup_payment_archives','signup_archive_events'] as $table) {
            try { $checks['schema '.$table]=DB::getSchemaBuilder()->hasTable($table); } catch (\Throwable $e) { $checks['schema '.$table]=false; }
        }
        try { $checks['account email schema']=DB::getSchemaBuilder()->hasColumns('users',['timezone','version']); } catch (\Throwable $e) { $checks['account email schema']=false; }
        if ($this->option('broker')) {
            foreach (['host','vhost','user','password','queue','events_queue','publisher_user','publisher_password'] as $key) $checks['broker '.$key]=is_string(config('broker.'.$key)) && config('broker.'.$key) !== '';
            $checks['broker readable CA']=is_readable(config('broker.ca_file',''));
            $checks['broker ACL attestation']=config('broker.verified_topic_acls') === true;
            $checks['NFC key']=strlen((string)config('nfc.hmac_key'))>=32;
        }
        foreach ($checks as $label=>$ok) $this->line(($ok ? 'PASS ' : 'FAIL ').$label);
        $this->warn('No live SES/S3, broker ACL/WSS, payment, hardware, load or restore verification is implied.');
        return in_array(false,$checks,true) ? self::FAILURE : self::SUCCESS;
    }
}
