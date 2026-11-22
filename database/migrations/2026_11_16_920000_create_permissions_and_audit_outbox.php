<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $t) { $t->string('key', 100)->primary(); });
        Schema::create('roles', function (Blueprint $t) { $t->string('code', 50)->primary(); });
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->string('role_code', 50); $t->string('permission_key', 100);
            $t->primary(['role_code', 'permission_key']);
            $t->foreign('role_code')->references('code')->on('roles')->restrictOnDelete();
            $t->foreign('permission_key')->references('key')->on('permissions')->restrictOnDelete();
        });
        Schema::table('memberships', function (Blueprint $t) { $t->unique(['organization_id', 'id']); });
        Schema::create('membership_roles', function (Blueprint $t) {
            $t->uuid('organization_id'); $t->uuid('membership_id'); $t->string('role_code', 50);
            $t->primary(['membership_id', 'role_code']);
            $t->foreign(['organization_id', 'membership_id'])->references(['organization_id', 'id'])->on('memberships')->restrictOnDelete();
            $t->foreign('role_code')->references('code')->on('roles')->restrictOnDelete();
        });
        Schema::create('audit_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id')->nullable(); $t->json('event');
            $t->timestampTz('created_at'); $t->timestampTz('delivered_at')->nullable();
            $t->index(['delivered_at', 'created_at']);
        });
        (new \Database\Seeders\PermissionSeeder)->run();
    }

    public function down(): void
    {
        foreach (['audit_outbox', 'membership_roles', 'role_permissions', 'roles', 'permissions'] as $table) Schema::dropIfExists($table);
        Schema::table('memberships', function (Blueprint $t) { $t->dropUnique(['organization_id', 'id']); });
    }
};
