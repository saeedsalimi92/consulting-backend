<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class EnsureAdmin {
 public function handle(Request $request, Closure $next, ?string $permission=null) {
  $user=$request->user();
  abort_unless($user && (bool)($user->is_active ?? true) && $user->canAccessAdmin(),403,'پنل مدیریت برای این کاربر مجاز نیست.');
  abort_unless($user->tokenCan('admin-panel'),403,'توکن برای پنل مدیریت معتبر نیست.');
  if ($permission) abort_unless($user->hasAdminPermission($permission),403,'دسترسی لازم را ندارید.');
  return $next($request);
 }
}
