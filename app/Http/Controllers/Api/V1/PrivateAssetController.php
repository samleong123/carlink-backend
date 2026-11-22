<?php
namespace App\Http\Controllers\Api\V1;

use App\Services\PrivateAssets;
use App\Support\{ApiResponse,CursorPage};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrivateAssetController
{
    public function __construct(private readonly PrivateAssets $assets) {}
    public function index(Request $r,string $organization)
    {
        $v=$r->validate(['target_type'=>'required|string','target_id'=>'required|uuid']);
        $this->assets->authorize($r,$organization,$v['target_type'],$v['target_id']); [$size,$cursor]=CursorPage::options($r);
        $page=DB::table('private_assets')->where('organization_id',$organization)->where('target_type',$v['target_type'])->where('target_id',$v['target_id'])->where('state','ready')->orderBy('id')->cursorPaginate($size,PrivateAssets::FIELDS,'cursor',$cursor);
        return ApiResponse::success($r,['items'=>$page->items(),'pagination'=>CursorPage::metadata($page)],'Private Attachments Retrieved');
    }
    public function store(Request $r,string $organization)
    {
        $v=$r->validate(['target_type'=>'required|in:organization,driver,vehicle,compliance_record,maintenance_order,mileage','target_id'=>'required|uuid',
            'purpose'=>'required|in:photo,road_tax,insurance,mileage_evidence,maintenance_evidence,document','filename'=>'required|string|max:160',
            'version'=>'nullable|integer|min:1','content_base64'=>'required|string|max:13981016']);
        $row=$this->assets->upload($r,$organization,$v);
        return ApiResponse::success($r,['item'=>$this->assets->metadata($row)],'Private Attachment Saved','',201);
    }
    public function link(Request $r,string $organization,string $asset)
    {
        $v=$r->validate(['mode'=>'nullable|in:view,download']); $row=$this->assets->row($r,$organization,$asset);
        return ApiResponse::success($r,['item'=>$this->assets->metadata($row),'link'=>$this->assets->link($r,$row,$v['mode'] ?? 'view')],'Private File Link Issued');
    }
    public function revoke(Request $r,string $organization,string $asset)
    {
        $v=$r->validate(['version'=>'required|integer|min:1']); $this->assets->row($r,$organization,$asset,true);
        DB::transaction(function() use($r,$organization,$asset,$v) {
            DB::table('organizations')->where('id',$organization)->lockForUpdate()->first(); $this->assets->row($r,$organization,$asset,true);
            $changed=DB::table('private_assets')->where('id',$asset)->where('organization_id',$organization)->where('state','ready')->where('version',$v['version'])->update(['state'=>'revoked','version'=>DB::raw('version + 1'),'updated_at'=>now()]); abort_unless($changed,409);
            // Keep the revoked pointer: clearing it could resurrect an older legacy photo.
            $audit=app(\App\Services\ActionAudit::class); $audit->outbox($audit->event('file.revoke','succeeded','http',$audit->context($r)+['asset_id'=>$asset]));
        });
        return ApiResponse::success($r,[],'Private Attachment Revoked','New links are blocked. Issued S3 links can remain usable until expiry or object deletion; cleanup retries deletion.');
    }
    public function content(Request $r,string $organization,string $asset)
    {
        // Retrieval returns a JSON link; bytes travel directly from private S3 to the browser.
        return $this->link($r,$organization,$asset);
    }
}
