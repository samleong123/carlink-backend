<?php
namespace App\Http\Controllers;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
class OrganizationController
{
 public function index(Request $request) { abort_unless($request->user()->status === 'active',403); $memberships = $request->user()->memberships()->where('status','active')->with('organization')->get(); return ApiResponse::success($request,['organizations'=>$memberships], 'Organizations Retrieved'); }
 public function show(Request $request) { return ApiResponse::success($request,['organization'=>$request->attributes->get('membership')->organization], 'Organization Retrieved'); }
}
