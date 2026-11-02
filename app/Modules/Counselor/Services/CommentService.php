<?php
namespace App\Modules\Counselor\Services;
use App\Modules\Counselor\Models\Comment;use App\Modules\Counselor\Repositories\CommentRepositoryInterface;
class CommentService {public function __construct(private CommentRepositoryInterface $repo){}public function index(array $filters=[]):\Illuminate\Contracts\Pagination\LengthAwarePaginator{return $this->repo->paginate($filters);}public function find(int $id):?Comment{return $this->repo->find($id);}public function create(array $data):Comment{return $this->repo->create($data);}public function update(Comment $model,array $data):Comment{return $this->repo->update($model,$data);}public function delete(Comment $model):void{$this->repo->delete($model);}}
