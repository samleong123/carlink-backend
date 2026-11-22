<?php
namespace App\Console\Commands;
use App\Services\OrganizationSignups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteUnpaidSignups extends Command
{
    protected $signature='signups:delete-unpaid {--limit=100}';
    protected $description='Permanently delete expired unpaid self-service organizations, archiving financial evidence';
    public function handle(OrganizationSignups $signups): int
    {
        $limit=filter_var($this->option('limit'),FILTER_VALIDATE_INT);
        if (!$limit || $limit<1 || $limit>1000) { $this->error('Limit must be between 1 and 1000.'); return self::FAILURE; }
        // Remove sensitive drafts after their email confirmation window, without touching financial evidence.
        DB::table('organization_signup_intents')->where('expires_at','<=',now())->whereNotNull('encrypted_payload')->update(['encrypted_payload'=>null,'updated_at'=>now()]);
        $deleted=0; $failed=false;
        foreach (DB::table('organization_signups')->where('status','pending')->where('payment_due_at','<=',now())->orderBy('payment_due_at')->limit($limit)->pluck('id') as $id) {
            try { if ($signups->deleteUnpaid($id)) $deleted++; }
            catch (\Throwable $e) { report($e); $failed=true; $this->error('Signup '.$id.' could not be deleted; review server diagnostics. No partial deletion committed.'); }
        }
        $this->info('Deleted '.$deleted.' unpaid signup organizations.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
