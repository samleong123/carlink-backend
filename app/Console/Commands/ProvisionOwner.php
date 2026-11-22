<?php
namespace App\Console\Commands;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProvisionOwner extends Command
{
    protected $signature = 'carlink:provision-owner {email} {organization} {--name=} {--slug=}';
    protected $description = 'Provision an organization and its initial owner for local or administrator setup';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        $organizationName = trim($this->argument('organization'));
        $slug = $this->option('slug') ?: Str::slug($organizationName);
        $name = $this->option('name') ?: $email;
        $validation = Validator::make(compact('email','organizationName','slug','name'), ['email'=>'required|email|max:255','organizationName'=>'required|string|max:255','slug'=>'required|alpha_dash|max:255|unique:organizations,slug','name'=>'required|string|max:255']);
        if ($validation->fails()) { foreach ($validation->errors()->all() as $message) { $this->error($message); } return self::FAILURE; }
        DB::transaction(function () use ($email,$name,$organizationName,$slug) {
            $user = User::firstOrCreate(['email_normalized'=>$email], ['display_name'=>$name, 'status'=>'active']);
            if ($user->status !== 'active') { throw new \RuntimeException('Existing user is not active.'); }
            $organization = Organization::create(['name'=>$organizationName,'slug'=>$slug,'status'=>'active']);
            $membership = Membership::create(['user_id'=>$user->id,'organization_id'=>$organization->id,'role'=>'owner','status'=>'active','joined_at'=>now()]);
            DB::table('membership_roles')->insertOrIgnore(['organization_id'=>$organization->id,'membership_id'=>$membership->id,'role_code'=>'owner']);
            app(\App\Services\ComplianceDefaults::class)->ensureTypes($organization->id);
        });
        $this->info('Organization and owner provisioned. The owner may request a login link.');
        return self::SUCCESS;
    }
}
