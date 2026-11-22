<?php
namespace App\Console\Commands;
use App\Services\Mail\NotificationEmails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Global human-account status is an operator responsibility, never inferred from tenant authority. */
class SetAccountStatus extends Command
{
    protected $signature='accounts:set-status {user : User UUID} {status : active or suspended}';
    protected $description='Trusted operator-only global account status change with durable security email';
    public function handle(): int
    {
        if (!\Illuminate\Support\Str::isUuid($this->argument('user')) || !in_array($this->argument('status'),['active','suspended'],true)) { $this->error('Specify a user UUID and active or suspended.'); return self::FAILURE; }
        return DB::transaction(function() {
            DB::table('platform_mutex')->where('id',1)->lockForUpdate()->first();
            $user=DB::table('users')->where('id',$this->argument('user'))->lockForUpdate()->first();
            if (!$user) { $this->error('Account not found.'); return self::FAILURE; }
            if ($user->status===$this->argument('status')) return self::SUCCESS;
            if ($this->argument('status')==='suspended' && DB::table('saas_admins')->where('user_id',$user->id)->where('role','admin')->where('status','active')->exists()
                && DB::table('saas_admins as s')->join('users as u','u.id','=','s.user_id')->where('s.role','admin')->where('s.status','active')->where('u.status','active')->count()<=1) { $this->error('Cannot suspend the last active SaaS admin.'); return self::FAILURE; }
            DB::table('users')->where('id',$user->id)->update(['status'=>$this->argument('status'),'version'=>$user->version+1,'updated_at'=>now()]);
            if ($this->argument('status')==='suspended') {
                DB::table('sessions')->where('user_id',$user->id)->delete();
                DB::table('magic_link_tokens')->where('user_id',$user->id)->whereNull('consumed_at')->update(['consumed_at'=>now(),'updated_at'=>now()]);
            }
            app(NotificationEmails::class)->status('user',DB::table('users')->where('id',$user->id)->first(),$user->id,null);
            $audit=app(\App\Services\ActionAudit::class); $audit->outbox($audit->event('account.status.update','succeeded','cli',['user_id'=>$user->id,'browser_absence_reason'=>'non_browser_execution']));
            return self::SUCCESS;
        });
    }
}
