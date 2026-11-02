<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminNotificationController extends Controller
{
    private function query(Request $request)
    {
        $permissions = $request->user()->is_admin ? null : array_values(array_filter([
            'dashboard.view', 'appointments.manage', 'finance.view', 'finance.manage', 'notes.manage', 'content.manage', 'users.manage',
        ], fn($permission) => $request->user()->hasAdminPermission($permission)));
        return DB::table('admin_notifications as n')->leftJoin('admin_notification_reads as r', function ($join) use ($request) {
            $join->on('r.admin_notification_id', '=', 'n.id')->where('r.user_id', '=', $request->user()->id);
        })->when($permissions !== null, fn($q) => $q->whereIn('n.permission', $permissions))
            ->select('n.*', 'r.read_at');
    }

    public function index(Request $request)
    {
        $v = $request->validate(['unread' => 'nullable|boolean', 'event' => 'nullable|string|max:100',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = $this->query($request)->when(($v['unread'] ?? false), fn($q) => $q->whereNull('r.read_at'))
            ->when($v['event'] ?? null, fn($q, $event) => $q->where('n.event', $event));
        if (!empty($v['from'])) $query->where('n.created_at', '>=', $v['from'].' 00:00:00');
        if (!empty($v['to'])) $query->where('n.created_at', '<=', $v['to'].' 23:59:59');
        $unread = (clone $this->query($request))->whereNull('r.read_at')->count();
        return response()->json(['data' => $query->orderByDesc('n.id')->paginate($v['per_page'] ?? 30), 'unread_count' => $unread]);
    }

    public function show(Request $request, int $notification)
    {
        $row = $this->query($request)->where('n.id', $notification)->first();
        abort_unless($row, 404);
        return response()->json(['data' => $row]);
    }

    public function read(Request $request, int $notification)
    {
        $row = $this->query($request)->where('n.id', $notification)->first();
        abort_unless($row, 404);
        DB::table('admin_notification_reads')->updateOrInsert(['admin_notification_id' => $notification, 'user_id' => $request->user()->id], ['read_at' => now()]);
        return response()->noContent();
    }

    public function readAll(Request $request)
    {
        $ids = $this->query($request)->whereNull('r.read_at')->pluck('n.id');
        foreach ($ids->chunk(500) as $chunk) {
            DB::table('admin_notification_reads')->insertOrIgnore($chunk->map(fn($id) => [
                'admin_notification_id' => $id, 'user_id' => $request->user()->id, 'read_at' => now(),
            ])->all());
        }
        return response()->json(['updated' => $ids->count()]);
    }
}
