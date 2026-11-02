<?php
namespace App\Modules\Counselor\Repositories;
use App\Modules\Counselor\Models\Comment;use Illuminate\Contracts\Pagination\LengthAwarePaginator;
interface CommentRepositoryInterface {public function paginate(array $filters=[]):LengthAwarePaginator;public function find(int $id):?Comment;public function create(array $data):Comment;public function update(Comment $model,array $data):Comment;public function delete(Comment $model):void;}
