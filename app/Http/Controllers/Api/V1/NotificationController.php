<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NotificationController
{
    private function own(Request $request, string $organization): Builder
    {
        return DB::table('notification_records')->where('organization_id', $organization)->where('user_id', $request->user()->id);
    }

    public function index(Request $request, string $organization)
    {
        $input = $request->validate([
            'type' => ['sometimes', 'string', 'max:120'], 'severity' => ['sometimes', 'string', 'max:24'],
            'unread' => ['sometimes', 'in:0,1,true,false'], 'cursor' => ['sometimes', 'string', 'max:2048'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $this->own($request, $organization)->whereNull('archived_at');
        if (isset($input['type'])) $query->where('kind', $input['type']);
        if (isset($input['severity'])) $query->where('severity', $input['severity']);
        if (isset($input['unread'])) $request->boolean('unread') ? $query->whereNull('read_at') : $query->whereNotNull('read_at');
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate((int) ($input['per_page'] ?? 50));
        $items = $page->getCollection()->map(fn ($row) => $this->render($row))->all();
        $count = $this->own($request, $organization)->whereNull('archived_at')->whereNull('read_at')->count();
        return ApiResponse::success($request, ['items' => $items, 'unread_count' => $count, 'pagination' => ['next_cursor' => $page->nextCursor()?->encode(), 'per_page' => $page->perPage()]], 'Notifications Retrieved');
    }

    public function unreadCount(Request $request, string $organization)
    {
        return ApiResponse::success($request, ['unread_count' => $this->own($request, $organization)->whereNull('archived_at')->whereNull('read_at')->count()], 'Unread Count Retrieved');
    }

    public function update(Request $request, string $organization, string $notification)
    {
        $input = $request->validate(['read' => ['sometimes', 'boolean'], 'archived' => ['sometimes', 'boolean']]);
        if (!$input) throw ValidationException::withMessages(['read' => ['Supply read or archived.']]);
        $row = DB::transaction(function () use ($request, $organization, $notification, $input) {
            $row = $this->own($request, $organization)->where('id', $notification)->lockForUpdate()->first();
            abort_unless($row, 404);
            $change = ['updated_at' => now()];
            if (array_key_exists('read', $input)) $change['read_at'] = $input['read'] ? ($row->read_at ?: now()) : null;
            if (array_key_exists('archived', $input)) $change['archived_at'] = $input['archived'] ? ($row->archived_at ?: now()) : null;
            $this->own($request, $organization)->where('id', $notification)->update($change);
            return $this->own($request, $organization)->where('id', $notification)->first();
        });
        return ApiResponse::success($request, ['item' => $this->render($row)], 'Notification Updated');
    }

    public function markRead(Request $request, string $organization)
    {
        $input = $request->validate([
            'ids' => ['sometimes', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'uuid', 'distinct'],
            'through_created_at' => ['sometimes', 'date'],
        ]);
        if ((isset($input['ids']) ? 1 : 0) + (isset($input['through_created_at']) ? 1 : 0) !== 1) {
            throw ValidationException::withMessages(['ids' => ['Supply either ids or through_created_at.']]);
        }
        $query = $this->own($request, $organization)->whereNull('read_at')->whereNull('archived_at');
        if (isset($input['ids'])) $query->whereIn('id', $input['ids']);
        else {
            $through = CarbonImmutable::parse($input['through_created_at'])->utc();
            if ($through->isFuture()) throw ValidationException::withMessages(['through_created_at' => ['Must not be in the future.']]);
            $query->where('created_at', '<=', $through);
        }
        $changed = $query->update(['read_at' => now(), 'updated_at' => now()]);
        return ApiResponse::success($request, ['updated_count' => $changed], 'Notifications Marked Read');
    }

    public function preferences(Request $request, string $organization)
    {
        $row = DB::table('notification_preferences')->where('organization_id', $organization)->where('user_id', $request->user()->id)->first();
        return ApiResponse::success($request, ['preferences' => $this->renderPreferences($row)], 'Notification Preferences Retrieved');
    }

    public function updatePreferences(Request $request, string $organization)
    {
        $input = $request->validate([
            'optional_email_enabled' => ['sometimes', 'boolean'], 'locale' => ['sometimes', 'nullable', 'in:en,ms,zh'],
            'digest_frequency' => ['sometimes', 'in:none,daily,weekly'], 'quiet_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'quiet_end' => ['sometimes', 'nullable', 'date_format:H:i'],
        ]);
        if (!$input) throw ValidationException::withMessages(['preferences' => ['Supply at least one supported preference.']]);
        $row = DB::transaction(function () use ($request, $organization, $input) {
            $userId = $request->user()->id;
            DB::table('notification_preferences')->insertOrIgnore(['id' => (string) Str::uuid(), 'organization_id' => $organization, 'user_id' => $userId, 'optional_email_enabled' => true, 'digest_frequency' => 'none', 'created_at' => now(), 'updated_at' => now()]);
            $query = DB::table('notification_preferences')->where('organization_id', $organization)->where('user_id', $userId);
            $current = $query->lockForUpdate()->first();
            $start = array_key_exists('quiet_start', $input) ? $input['quiet_start'] : $current->quiet_start;
            $end = array_key_exists('quiet_end', $input) ? $input['quiet_end'] : $current->quiet_end;
            if (($start === null) !== ($end === null)) throw ValidationException::withMessages(['quiet_start' => ['Both quiet-hour boundaries must be supplied or cleared.']]);
            $query->update($input + ['updated_at' => now()]);
            return DB::table('notification_preferences')->where('organization_id', $organization)->where('user_id', $userId)->first();
        });
        return ApiResponse::success($request, ['preferences' => $this->renderPreferences($row)], 'Notification Preferences Updated');
    }

    private function renderPreferences(?object $row): array
    {
        return ['optional_email_enabled' => $row ? (bool) $row->optional_email_enabled : true, 'locale' => $row->locale ?? null,
            'digest_frequency' => $row->digest_frequency ?? 'none', 'quiet_start' => $row->quiet_start ?? null, 'quiet_end' => $row->quiet_end ?? null];
    }

    private function render(object $row): array
    {
        $route = $row->action_route;
        $action = $route && preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $route) ? ['route' => $route, 'parameters' => $this->jsonObject($row->action_parameters)] : null;
        return ['id' => $row->id, 'type' => $row->kind, 'severity' => $row->severity, 'title' => $row->title, 'message' => $row->message,
            'subject' => $row->subject_id && $row->subject_type ? ['type' => $row->subject_type, 'id' => $row->subject_id] : null,
            'action' => $action, 'occurred_at' => $this->iso($row->occurred_at), 'created_at' => $this->iso($row->created_at),
            'read_at' => $this->iso($row->read_at), 'archived_at' => $this->iso($row->archived_at), 'data' => $this->jsonObject($row->parameters)];
    }

    private function jsonObject(?string $value): object
    {
        $decoded = $value === null ? null : json_decode($value);
        return is_object($decoded) ? $decoded : (object) [];
    }

    private function iso(?string $value): ?string { return $value === null ? null : CarbonImmutable::parse($value)->utc()->toIso8601String(); }
}
