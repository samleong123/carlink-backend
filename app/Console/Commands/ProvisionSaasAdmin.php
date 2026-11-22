<?php
namespace App\Console\Commands;
use App\Models\User;
use App\Services\ActionAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProvisionSaasAdmin extends Command
{
    protected $signature='carlink:provision-saas-admin {email} {--name=Platform Owner}';
    protected $description='Bootstrap the first SaaS admin from a trusted server terminal, without passwords';
    public function handle(): int
    {
        $email=strtolower(trim($this->argument('email')));
        if (Validator::make(['email'=>$email],['email'=>'required|email|max:255'])->fails()) { $this->error('Valid email required.'); return self::FAILURE; }
        DB::transaction(function () use ($email) {
            DB::table('platform_mutex')->where('id',1)->lockForUpdate()->first();
            abort_if(DB::table('saas_admins')->exists(),409,'Use the authenticated SaaS staff API after bootstrap.');
            $u=User::firstOrCreate(['email_normalized'=>$email],['display_name'=>$this->option('name'),'status'=>'active']);
            DB::table('saas_admins')->insert(['user_id'=>$u->id,'role'=>'admin','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
            $audit=app(ActionAudit::class); $audit->outbox($audit->event('platform.bootstrap','succeeded','cli',['user_id'=>$u->id,'browser_absence_reason'=>'non_browser_execution']));
        });
        $this->info('SaaS admin created. Request an email login link; no password or login token was printed.'); return self::SUCCESS;
    }
}
