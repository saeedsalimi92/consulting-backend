<?php
namespace App\Modules\Counselor\Policies;
use App\Models\User;use App\Modules\Counselor\Models\Counselor;
class CounselorPolicy {public function viewAny(?User $user):bool{return true;}public function view(?User $user,Counselor $model):bool{return true;}public function create(User $user):bool{return $user->hasAdminPermission('counselors.manage');}public function update(User $user,Counselor $model):bool{return $user->hasAdminPermission('counselors.manage');}public function delete(User $user,Counselor $model):bool{return $user->hasAdminPermission('counselors.manage');}}
