<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('private_assets',function(Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->string('target_type',32); $t->uuid('target_id'); $t->string('purpose',32);
            $t->string('disk',20); $t->string('object_key')->unique(); $t->string('filename',160); $t->string('mime_type',40);
            $t->unsignedInteger('size_bytes'); $t->string('sha256',64); $t->foreignUuid('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('state',20)->default('uploading'); $t->unsignedInteger('version')->default(1); $t->timestampsTz();
            $t->timestampTz('purged_at')->nullable(); $t->index(['organization_id','target_type','target_id','state']);
        });
        Schema::table('drivers',fn(Blueprint $t)=>$t->uuid('photo_asset_id')->nullable());
        Schema::table('organizations',fn(Blueprint $t)=>$t->uuid('photo_asset_id')->nullable());
    }
    public function down(): void { throw new LogicException('Private evidence requires a reviewed forward rollback.'); }
};
