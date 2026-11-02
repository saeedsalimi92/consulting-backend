<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminRolePermission extends Model
{
    public $timestamps = false;
    protected $table = 'admin_role_permissions';
    protected $fillable = ['role_id', 'permission'];
}
