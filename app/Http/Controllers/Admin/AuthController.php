<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Hash,RateLimiter};
use Illuminate\Validation\ValidationException;
class AuthController extends Controller {
 private function userPayload(User $user): array { $permissions=$user->is_admin?['*']:array_values(array_unique(array_merge($user->admin_permissions??[],DB::table('admin_role_permissions as p')->join('admin_user_roles as ur','ur.role_id','=','p.role_id')->where('ur.user_id',$user->id)->pluck('p.permission')->all()))); return ['id'=>$user->id,'name'=>$user->name,'email'=>$user->email,'is_admin'=>(bool)$user->is_admin,'permissions'=>$permissions]; }
 public function login(Request $request) {
  $v=$request->validate(['email'=>'required|email','password'=>'required|string']);
  $key='admin-login:'.sha1(strtolower($v['email']).'|'.$request->ip());
  if (RateLimiter::tooManyAttempts($key,5)) throw ValidationException::withMessages(['email'=>['تلاش‌های ناموفق زیاد است؛ بعداً تلاش کنید.']]);
  $user=User::where('email',$v['email'])->first();
  if (!$user || !(bool)($user->is_active ?? true) || !Hash::check($v['password'],$user->password) || !$user->canAccessAdmin()) {
   RateLimiter::hit($key,60);throw ValidationException::withMessages(['email'=>['ایمیل یا رمز عبور نامعتبر است.']]);
  }
  RateLimiter::clear($key);
  return response()->json(['token'=>$user->createToken('modernize-admin',['admin-panel'])->plainTextToken,'user'=>$this->userPayload($user)]);
 }
 public function me(Request $request) {return response()->json(['user'=>$this->userPayload($request->user())]);}
 public function logout(Request $request) {$request->user()->currentAccessToken()?->delete();return response()->noContent();}
}
