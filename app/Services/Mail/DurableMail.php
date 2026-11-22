<?php

namespace App\Services\Mail;

use App\Services\ActionAudit;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DurableMail
{
    /** Allow live email delivery only through Amazon SES (or Laravel array mailer during PHPUnit runs). */
    public static function deliveryEnabled(): bool
    {
        return in_array(config('mail.default'), app()->runningUnitTests() ? ['ses', 'array'] : ['ses'], true);
    }

    public function enqueue(string $kind, string $source, string $user, ?string $org, string $subject, string $body, mixed $expires, ?string $html=null, ?string $recipient=null): string
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Mail intent must commit with its token.');
        if (!in_array($kind,['login','invitation','notification'],true)) throw new \InvalidArgumentException('Unsupported mail intent.');
        $id=(string)Str::uuid();
        DB::table('mail_outbox')->insert(['id'=>$id,'organization_id'=>$org,'user_id'=>$user,'kind'=>$kind,'source_id'=>$source,
            'encrypted_message'=>Crypt::encryptString(json_encode(['subject'=>$subject,'body'=>$body,'html'=>$html,'recipient'=>$recipient ?? DB::table('users')->where('id',$user)->value('email_normalized')],JSON_THROW_ON_ERROR)),
            'expires_at'=>$expires,'available_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        if (!app()->runningUnitTests()) {
            DB::afterCommit(function () use ($id, $kind) {
                if (self::deliveryEnabled()) {
                    try {
                        \App\Jobs\DeliverMailIntent::dispatch($id)->onQueue($kind === 'notification' ? 'notifications' : 'auth-mail');
                    } catch (\Throwable $e) {
                        // The scheduled artisan mail:dispatch-pending command remains as durable fallback
                    }
                }
            });
        }
        return $id;
    }

    public function send(string $id): void
    {
        // Never write authentication credentials through Laravel's log transport or unapproved mailers.
        if (!self::deliveryEnabled()) throw new \LogicException('Amazon SES mail transport is required.');
        $lease=(string)Str::uuid();
        $row=DB::transaction(function () use ($id,$lease) {
            $r=DB::table('mail_outbox')->where('id',$id)->lockForUpdate()->first();
            if (!$r || $r->state !== 'pending' || now()->lt($r->available_at) || ($r->locked_until && now()->lt($r->locked_until))) return null;
            if ($r->kind === 'notification') {
                $source=DB::table('email_notifications')->where('id',$r->source_id)->where('user_id',$r->user_id)->first();
                $user=DB::table('users')->where('id',$r->user_id)->first();
                $message=$r->encrypted_message ? json_decode(Crypt::decryptString($r->encrypted_message),true,flags:JSON_THROW_ON_ERROR) : [];
                if (!$source || !$user || !app(NotificationEmails::class)->eligible($source) || now()->gte($r->expires_at) || ($message['recipient'] ?? null)!==$user->email_normalized) {
                    DB::table('mail_outbox')->where('id',$id)->update(['state'=>'cancelled','encrypted_message'=>null,'lease_token'=>null,'locked_until'=>null,'updated_at'=>now()]); return null;
                }
                DB::table('mail_outbox')->where('id',$id)->update(['lease_token'=>$lease,'locked_until'=>now()->addSeconds(90),'attempts'=>$r->attempts+1,'updated_at'=>now()]);
                $r->recipient=$user->email_normalized; return $r;
            }
            $user=DB::table('users')->where('id',$r->user_id)->where('status','active')->first();
            $source=$r->kind === 'login' ? DB::table('magic_link_tokens')->where('id',$r->source_id)->whereNull('consumed_at')->first()
                : DB::table('organization_invitations')->where('id',$r->source_id)->whereNull('accepted_at')->whereNull('revoked_at')->first();
            $orgActive=!$r->organization_id || DB::table('organizations')->where('id',$r->organization_id)->where('status','active')->exists();
            if (!$user || !$source || !$orgActive || now()->gte($r->expires_at) || now()->gte($source->expires_at) || $source->email_normalized !== $user->email_normalized
                || ($r->kind === 'invitation' && $source->organization_id !== $r->organization_id)
                || ($r->kind === 'login' && $source->user_id !== $r->user_id)) {
                DB::table('mail_outbox')->where('id',$id)->update(['state'=>'cancelled','encrypted_message'=>null,'lease_token'=>null,'locked_until'=>null,'updated_at'=>now()]); return null;
            }
            DB::table('mail_outbox')->where('id',$id)->update(['lease_token'=>$lease,'locked_until'=>now()->addSeconds(90),'attempts'=>$r->attempts+1,'updated_at'=>now()]);
            $r->recipient=$user->email_normalized; return $r;
        });
        if (!$row) return;
        try {
            $message=json_decode(Crypt::decryptString($row->encrypted_message),true,flags:JSON_THROW_ON_ERROR);
            $sent=Mail::send(['html'=>new \Illuminate\Support\HtmlString($message['html'] ?? app(EmailTemplate::class)->render($message['subject'],$message['body'])['html']),
                'text'=>new \Illuminate\Support\HtmlString($message['body'])],[],fn($m)=>$m->to($row->recipient)->subject($message['subject']));
            if ($sent === null) throw new \RuntimeException('Mail was not accepted by the transport.');
        } catch (\Throwable $e) {
            DB::table('mail_outbox')->where('id',$id)->where('lease_token',$lease)->update(['lease_token'=>null,'locked_until'=>null,'available_at'=>now()->addSeconds(min(300,2 ** min(8,$row->attempts+1))),'last_error'=>'transport_failure','updated_at'=>now()]);
            // Do not attach provider exception text or encrypted contents to failed Redis jobs.
            throw new \RuntimeException('Mail transport failed; durable retry remains pending.');
        }
        DB::transaction(function () use ($id,$lease,$row) {
            $changed=DB::table('mail_outbox')->where('id',$id)->where('lease_token',$lease)->update(['state'=>'provider_accepted','provider_accepted_at'=>now(),'encrypted_message'=>null,'lease_token'=>null,'locked_until'=>null,'last_error'=>null,'updated_at'=>now()]);
            if ($changed) { $audit=app(ActionAudit::class); $audit->outbox($audit->event('mail.provider_accept','succeeded','queue',['organization_id'=>$row->organization_id,'mail_intent_id'=>$id,'browser_absence_reason'=>'non_browser_execution'])); }
        });
    }
}
