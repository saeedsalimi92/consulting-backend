<?php
namespace App\Modules\Counselor\Policies;
use App\Models\User;use App\Modules\Counselor\Models\Comment;
class CommentPolicy {public function viewAny(?User $user):bool{return true;}public function view(?User $user,Comment $model):bool{return true;}public function create(User $user):bool{return $user->hasAdminPermission('comments.manage');}public function update(User $user,Comment $model):bool{return $user->hasAdminPermission('comments.manage');}public function delete(User $user,Comment $model):bool{return $user->hasAdminPermission('comments.manage');}}
