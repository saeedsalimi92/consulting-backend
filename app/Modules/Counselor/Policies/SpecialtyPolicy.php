<?php
namespace App\Modules\Counselor\Policies;
use App\Models\User;use App\Modules\Counselor\Models\Specialty;
class SpecialtyPolicy {public function viewAny(?User $user):bool{return true;}public function view(?User $user,Specialty $model):bool{return true;}public function create(User $user):bool{return $user->hasAdminPermission('specialties.manage');}public function update(User $user,Specialty $model):bool{return $user->hasAdminPermission('specialties.manage');}public function delete(User $user,Specialty $model):bool{return $user->hasAdminPermission('specialties.manage');}}
