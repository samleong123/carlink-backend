<?php
namespace App\Http\Controllers\Api\V1;
use App\Services\DtcAi;
use App\Services\Permissions;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class DtcAiController
{
    public function explain(Request $r, string $organization)
    {
        abort_unless(app(Permissions::class)->allows($r->attributes->get('membership'),'telemetry.read'),403);
        return $this->answer($r);
    }
    public function device(Request $r, string $device) { return $this->answer($r); }
    private function answer(Request $r)
    {
        $r->merge(['code'=>strtoupper(trim((string)$r->input('code')))]);
        $v=$r->validate(['code'=>['required','regex:/^[PCBU][0-3][0-9A-F]{3}$/D']]);
        return ApiResponse::success($r,app(DtcAi::class)->explain($v['code']),'DTC AI Explanation','Advisory AI output, not a verified diagnosis. Not stored by CarLink; the code is sent to Google.');
    }
}
