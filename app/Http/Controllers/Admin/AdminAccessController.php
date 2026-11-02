<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminAccessController extends Controller
{
    private const PERMISSIONS = ['dashboard.view','users.manage','counselors.manage','specialties.manage','comments.manage',
        'content.manage','appointments.manage','settings.manage','finance.view','finance.manage','notes.manage'];

    private function requireSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->is_admin, 403);
    }

    public function roles(Request $request)
    {
        $this->requireSuperAdmin($request);
        return response()->json(['data' => AdminRole::with('permissions:id,role_id,permission')->orderBy('name')->get()]);
    }

    public function permissions(Request $request)
    {
        $this->requireSuperAdmin($request);
        return response()->json(['data' => self::PERMISSIONS]);
    }

    public function createRole(Request $request)
    {
        $this->requireSuperAdmin($request);
        $values = $request->validate(['name' => ['required','string','max:80','regex:/^[a-z][a-z0-9_-]{2,79}$/','not_in:super_admin,client,counselor',Rule::unique('admin_roles','name')],
            'label' => 'required|string|max:120','permissions'=>'required|array|max:20','permissions.*'=>['string','distinct',Rule::in(self::PERMISSIONS)]]);
        $role = DB::transaction(function () use ($values) {
            $role = AdminRole::create(['name'=>$values['name'],'label'=>$values['label'],'is_assignable'=>true]);
            foreach ($values['permissions'] as $permission) $role->permissions()->create(['permission'=>$permission]);
            return $role->load('permissions');
        });
        DB::table('booking_audit_events')->insert(['actor_id'=>$request->user()->id,'action'=>'admin.role_created',
            'details'=>json_encode(['role_id'=>$role->id,'name'=>$role->name,'permissions'=>$values['permissions']]),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>$role],201);
    }

    public function updateRole(Request $request, int $role)
    {
        $this->requireSuperAdmin($request);
        $model = AdminRole::findOrFail($role);
        $values = $request->validate([
            'label' => 'sometimes|required|string|max:120',
            'permissions' => 'sometimes|required|array|max:20',
            'permissions.*' => ['string', 'distinct', Rule::in(self::PERMISSIONS)],
        ]);
        DB::transaction(function () use ($model, $values) {
            if (array_key_exists('label', $values)) $model->update(['label' => $values['label']]);
            if (array_key_exists('permissions', $values)) {
                $model->permissions()->delete();
                foreach ($values['permissions'] as $permission) $model->permissions()->create(['permission' => $permission]);
                $userIds=DB::table('admin_user_roles')->where('role_id',$model->id)->pluck('user_id');
                DB::table('personal_access_tokens')->where('tokenable_type',User::class)->whereIn('tokenable_id',$userIds)->delete();
            }
        });
        DB::table('booking_audit_events')->insert(['actor_id' => $request->user()->id, 'action' => 'admin.role_updated',
            'details' => json_encode(['role_id' => $role, 'fields' => array_keys($values)]), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => $model->fresh()->load('permissions')]);
    }

    public function deleteRole(Request $request, int $role)
    {
        $this->requireSuperAdmin($request);
        $model=AdminRole::findOrFail($role);
        abort_if(in_array($model->name,['reception','finance','clinical','content','operations'],true),409,'Built-in roles cannot be deleted.');
        abort_if(DB::table('admin_user_roles')->where('role_id',$role)->exists(),409,'Role is assigned to users.');
        DB::transaction(fn()=>$model->delete());
        DB::table('booking_audit_events')->insert(['actor_id'=>$request->user()->id,'action'=>'admin.role_deleted','details'=>json_encode(['role_id'=>$role]),'created_at'=>now(),'updated_at'=>now()]);
        return response()->noContent();
    }

    public function userRoles(Request $request, User $user)
    {
        $this->requireSuperAdmin($request);
        return response()->json(['data' => $user->roles()->with('permissions')->get()]);
    }

    public function assignUserRoles(Request $request, User $user)
    {
        $this->requireSuperAdmin($request);
        $values = $request->validate(['role_ids' => 'required|array|max:20', 'role_ids.*' => 'integer|distinct|exists:admin_roles,id']);
        $roles = AdminRole::whereIn('id', $values['role_ids'])->where('is_assignable', true)->pluck('id')->all();
        abort_unless(count($roles) === count($values['role_ids']), 422, 'One or more roles are not assignable.');
        DB::transaction(function () use ($request, $user, $roles) {
            $user->roles()->sync(collect($roles)->mapWithKeys(fn ($id) => [$id => ['assigned_by' => $request->user()->id]]));
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
            DB::table('booking_audit_events')->insert(['actor_id' => $request->user()->id, 'action' => 'admin.user_roles_updated',
                'details' => json_encode(['user_id' => $user->id, 'role_ids' => $roles]), 'created_at' => now(), 'updated_at' => now()]);
        });
        return response()->json(['data' => $user->roles()->with('permissions')->get()]);
    }

    public function auditLog(Request $request)
    {
        $this->requireSuperAdmin($request);
        $v = $request->validate(['actor_id' => 'nullable|integer|exists:users,id', 'action' => 'nullable|string|max:120',
            'booking_id' => 'nullable|integer|exists:bookings,id', 'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d', 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = DB::table('booking_audit_events as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->select('a.id', 'a.actor_id', 'u.name as actor_name', 'a.booking_id', 'a.action', 'a.details', 'a.created_at')
            ->when($v['actor_id'] ?? null, fn($q, $id) => $q->where('a.actor_id', $id))
            ->when($v['booking_id'] ?? null, fn($q, $id) => $q->where('a.booking_id', $id))
            ->when($v['action'] ?? null, fn($q, $action) => $q->where('a.action', 'like', '%'.$action.'%'));
        if (!empty($v['from'])) $query->where('a.created_at', '>=', $v['from'].' 00:00:00');
        if (!empty($v['to'])) $query->where('a.created_at', '<=', $v['to'].' 23:59:59');
        return response()->json(['data' => $query->orderByDesc('a.id')->paginate($v['per_page'] ?? 30)]);
    }
}
