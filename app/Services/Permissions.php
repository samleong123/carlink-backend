<?php

namespace App\Services;

use App\Models\Membership;
use Illuminate\Support\Facades\DB;

class Permissions
{
    public function keys(Membership $membership): array
    {
        // No permission cache: revocation and role edits take effect on the next check.
        $current = Membership::whereKey($membership->id)->where('organization_id', $membership->organization_id)
            ->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->whereHas('organization', fn ($q) => $q->whereIn('status', ['active','pending_payment']))->first();
        if (!$current) return [];
        $roles = DB::table('membership_roles')->where('organization_id', $current->organization_id)->where('membership_id', $current->id)->pluck('role_code')->all();
        $roles[] = $current->role;
        $keys=DB::table('role_permissions')->whereIn('role_code', $roles)->distinct()->orderBy('permission_key')->pluck('permission_key')->all();
        if ($current->organization->status === 'pending_payment') return array_values(array_intersect($keys,['subscription.read','payments.manage']));
        return $keys;
    }

    public function allows(?Membership $membership, string $key): bool
    {
        return $membership !== null && in_array($key, $this->keys($membership), true);
    }
}
