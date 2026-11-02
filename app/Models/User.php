<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
class User extends Authenticatable {
 use HasApiTokens, Notifiable;
 private static ?bool $counselorUserLinkAvailable = null;
 protected $fillable=['name','first_name','last_name','email','password','phone','phone_verified_at','last_otp_verified_at','pending_phone','national_id','birth_date','address','is_admin','is_active','admin_permissions'];
 protected $hidden=['password','remember_token'];
 protected function casts(): array {return ['email_verified_at'=>'datetime','phone_verified_at'=>'datetime','last_otp_verified_at'=>'datetime','password'=>'hashed','is_admin'=>'boolean','admin_permissions'=>'array'];}
 public function hasAdminPermission(string $permission): bool {
  if ((bool)$this->is_admin || in_array($permission, $this->admin_permissions ?? [], true)) return true;
  return Schema::hasTable('admin_user_roles') && $this->roles()->whereHas('permissions', fn($q) => $q->where('permission', $permission))->exists();
 }
 public function canAccessAdmin(): bool {return (bool)$this->is_admin || count($this->admin_permissions ?? [])>0 || (Schema::hasTable('admin_user_roles') && $this->roles()->exists());}
 public function roles() {return $this->belongsToMany(\App\Models\AdminRole::class, 'admin_user_roles', 'user_id', 'role_id')->withPivot(['assigned_by'])->withTimestamps();}
 public function isCounselorAccount(): bool {
  self::$counselorUserLinkAvailable ??= Schema::hasColumn('counselors','user_id');
  return self::$counselorUserLinkAvailable && $this->counselor()->exists();
 }
 public function canAccessClientPortal(): bool {return !$this->canAccessAdmin() && !$this->isCounselorAccount();}
 public function counselor() {return $this->hasOne(\App\Modules\Counselor\Models\Counselor::class);}
}
