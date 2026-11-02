<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminRole extends Model
{
    protected $table = 'admin_roles';
    protected $fillable = ['name', 'label', 'is_assignable'];
    protected $casts = ['is_assignable' => 'boolean'];

    public function permissions()
    {
        return $this->hasMany(AdminRolePermission::class, 'role_id');
    }
}
