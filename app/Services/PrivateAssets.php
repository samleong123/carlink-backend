<?php
namespace App\Services;

use App\Exceptions\FileDependencyUnavailable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;

class PrivateAssets
{
    public const LINK_TTL_SECONDS=300;
    public const FIELDS=['id','target_type','target_id','purpose','filename','mime_type','size_bytes','version','created_at'];
    private const TARGETS=[
        'organization'=>['organizations','organizations.read','organizations.write'],
        'driver'=>['drivers','drivers.read','drivers.write'],
        'vehicle'=>['vehicles','vehicles.read','vehicles.write'],
        'compliance_record'=>['compliance_records','compliance.read','compliance.write'],
        'maintenance_order'=>['maintenance_work_orders','maintenance.read','maintenance.write'],
        'mileage'=>['vehicles','mileage.read','mileage.correct'],
    ];
    public function authorize(Request $r,string $org,string $type,string $id,bool $write=false): object
    {
        abort_unless(isset(self::TARGETS[$type]),422,'Unsupported attachment target.');
        [$table,$read,$edit]=self::TARGETS[$type];
        $q=DB::table($table)->where('id',$id);
        $row=($table==='organizations' ? $q->where('id',$org) : $q->where('organization_id',$org))->first() ?? abort(404);
        $p=app(Permissions::class); $membership=$r->attributes->get('membership');
        if ($p->allows($membership,$write ? $edit : $read)) return $row;
        if (!$write && $p->allows($membership,'profile.own')) {
            $driver=DB::table('drivers')->where('organization_id',$org)->where('user_id',$r->user()->id)->where('status','active')->first();
            if ($driver && $type==='driver' && $row->id===$driver->id) return $row;
            if ($driver && in_array($type,['compliance_record','maintenance_order'],true)) {
                $subject=$type==='compliance_record' ? DB::table('compliance_obligations')->where('organization_id',$org)->where('id',$row->obligation_id)->first() : $row;
                if ($subject && (($subject->driver_id ?? null)===$driver->id || (($subject->vehicle_id ?? null) && DB::table('driver_shifts')->where('organization_id',$org)->where('driver_id',$driver->id)->where('vehicle_id',$subject->vehicle_id)->where('state','active')->whereNull('ended_at')->where('attribution_expires_at','>',now())->exists()))) return $row;
            }
        }
        abort(403);
    }
    public function row(Request $r,string $org,string $id,bool $write=false): object
    {
        $row=DB::table('private_assets')->where('organization_id',$org)->where('id',$id)->where('state','ready')->first() ?? abort(404);
        $this->authorize($r,$org,$row->target_type,$row->target_id,$write); return $row;
    }
    public function metadata(object $row): array { return array_intersect_key((array)$row,array_flip(self::FIELDS)); }
    public function upload(Request $r,string $org,array $v): object
    {
        $target=$this->authorize($r,$org,$v['target_type'],$v['target_id'],true);
        $photo=$v['purpose']==='photo';
        abort_if($photo && !in_array($v['target_type'],['organization','driver'],true),422,'Photo targets must be organizations or drivers.');
        $specific=['road_tax'=>'compliance_record','insurance'=>'compliance_record','mileage_evidence'=>'mileage','maintenance_evidence'=>'maintenance_order'];
        if (isset($specific[$v['purpose']])) abort_unless($v['target_type']===$specific[$v['purpose']],422,'Evidence must be attached to its matching module target.');
        if ($photo && $v['target_type']==='driver') {
            abort_unless(isset($v['version']),422,'Current driver version is required.');
            abort_unless((int)$target->version===(int)$v['version'],409,'Driver version changed.');
        }
        $content=app(FileContentValidator::class)->validate($v['content_base64'],$photo);
        $id=(string)Str::uuid(); $disk=config('attachments.disk'); if ($disk!=='s3') throw FileDependencyUnavailable::storage();
        $key="private-assets/$org/$id.".$content['extension'];
        $name=preg_replace('/[^A-Za-z0-9._-]/','_',pathinfo($v['filename'],PATHINFO_FILENAME));
        $row=['id'=>$id,'organization_id'=>$org,'target_type'=>$v['target_type'],'target_id'=>$v['target_id'],'purpose'=>$v['purpose'],
            'disk'=>$disk,'object_key'=>$key,'filename'=>substr($name ?: 'attachment',0,140).'.'.$content['extension'],'mime_type'=>$content['mime_type'],
            'size_bytes'=>strlen($content['bytes']),'sha256'=>hash('sha256',$content['bytes']),'uploaded_by'=>$r->user()->id,'state'=>'uploading','version'=>1,'created_at'=>now(),'updated_at'=>now()];
        // Track the object before network work so crashes leave a reconcilable row.
        DB::table('private_assets')->insert($row);
        try {
            try { $ok=Storage::disk($disk)->put($key,$content['bytes'],['visibility'=>'private','ContentType'=>$content['mime_type'],
                // SDK/Flysystem otherwise sends ACL=private, rejected by BucketOwnerEnforced buckets.
                // Omitted ACL defaults private; enforce Block Public Access and private bucket/IAM policy.
                'mup_threshold'=>16777216,'before_upload'=>static function($command) { unset($command['ACL']); }]); }
            catch (\Throwable $e) { throw FileDependencyUnavailable::storage($e); }
            if (!$ok) throw FileDependencyUnavailable::storage();
            DB::transaction(function() use($r,$org,$v,$id,$photo) {
                DB::table('organizations')->where('id',$org)->lockForUpdate()->first();
                $target=$this->authorize($r,$org,$v['target_type'],$v['target_id'],true);
                if ($photo) {
                    $table=$v['target_type']==='driver' ? 'drivers' : 'organizations';
                    $q=DB::table($table)->where('id',$v['target_id']); if ($table==='drivers') $q->where('organization_id',$org);
                    $target=(clone $q)->lockForUpdate()->first() ?? abort(404);
                    if ($table==='drivers') abort_unless((int)$target->version===(int)$v['version'],409,'Driver version changed.');
                    if ($target->photo_asset_id) DB::table('private_assets')->where('organization_id',$org)->where('id',$target->photo_asset_id)->update(['state'=>'revoked','version'=>DB::raw('version + 1'),'updated_at'=>now()]);
                    $updates=['photo_asset_id'=>$id,'updated_at'=>now()]; if ($table==='drivers') $updates['version']=$target->version+1;
                    if ($table==='drivers' && $target->user_id) app(\App\Services\Mail\NotificationEmails::class)->queue('account.details','photo:'.$id,$target->user_id,null,'Your driver photo was updated','An organization updated your driver profile photo. Contact an administrator if you did not expect this change.',['Changed fields'=>'Profile photo']);
                    $q->update($updates);
                }
                abort_unless(DB::table('private_assets')->where('id',$id)->where('state','uploading')->update(['state'=>'ready','updated_at'=>now()]),409,'Upload requires reconciliation.');
                $audit=app(ActionAudit::class); $audit->outbox($audit->event($photo ? $v['target_type'].'.photo.update' : 'file.upload','succeeded','http',$audit->context($r)+['asset_id'=>$id]));
            });
        } catch (\Throwable $e) {
            DB::table('private_assets')->where('id',$id)->update(['state'=>'failed','updated_at'=>now()]);
            $this->purge((object)$row); throw $e;
        }
        return DB::table('private_assets')->where('id',$id)->first();
    }
    public function link(Request $r,object $row,string $mode='view'): array
    {
        abort_unless(in_array($mode,['view','download'],true),422);
        // Recheck readiness and target authority at issuance, including photo/role changes.
        $row=$this->row($r,$row->organization_id,$row->id);
        if ($row->disk!=='s3' || config('attachments.disk')!=='s3') throw new FileDependencyUnavailable('FILE_LINK_SIGNING_UNAVAILABLE');
        $expires=now()->addSeconds(self::LINK_TTL_SECONDS);
        $inline=$mode==='view' && in_array($row->mime_type,['image/jpeg','image/png'],true);
        $name=preg_replace('/[^A-Za-z0-9._-]/','_',substr($row->filename,0,160)) ?: 'attachment';
        try {
            $url=Storage::disk('s3')->temporaryUrl($row->object_key,$expires,[
                'ResponseContentType'=>$row->mime_type,
                'ResponseContentDisposition'=>($inline ? 'inline' : 'attachment').'; filename="'.$name.'"',
                'ResponseCacheControl'=>'private, no-store',
            ]);
            $parts=is_string($url) ? parse_url($url) : false;
            if (!$parts || ($parts['scheme'] ?? '')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) throw new \RuntimeException('Invalid storage link.');
        } catch (\Throwable $e) {
            // Never retain/log a presigned URL, credential identifier, SDK message or object key.
            throw new FileDependencyUnavailable('FILE_LINK_SIGNING_UNAVAILABLE');
        }
        return ['url'=>$url,'expires_at'=>$expires->toIso8601String(),'valid_for_seconds'=>self::LINK_TTL_SECONDS,'requires_authenticated_session'=>false];
    }
    public function purge(object $row): bool
    {
        try {
            if (!Storage::disk($row->disk)->delete($row->object_key)) return false;
            DB::table('private_assets')->where('id',$row->id)->whereIn('state',['failed','revoked'])->update(['purged_at'=>now()]); return true;
        } catch (\Throwable $e) { return false; }
    }
}
