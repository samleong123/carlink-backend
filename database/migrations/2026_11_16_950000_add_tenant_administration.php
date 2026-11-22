<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $t) { $t->unsignedInteger('version')->default(1); });
        Schema::create('organization_settings', function (Blueprint $t) {
            $t->uuid('organization_id')->primary(); $t->json('settings'); $t->unsignedInteger('version')->default(1); $t->uuid('updated_by')->nullable(); $t->timestampsTz();
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('organization_settings'); Schema::table('memberships', function (Blueprint $t) { $t->dropColumn('version'); });
    }
};
