<?php
namespace App\Modules\Counselor\Services;
use App\Modules\Counselor\Models\Specialty;use App\Modules\Counselor\Repositories\SpecialtyRepositoryInterface;
class SpecialtyService {public function __construct(private SpecialtyRepositoryInterface $repo){}public function index(array $filters=[]):\Illuminate\Contracts\Pagination\LengthAwarePaginator{return $this->repo->paginate($filters);}public function find(int $id):?Specialty{return $this->repo->find($id);}public function create(array $data):Specialty{return $this->repo->create($data);}public function update(Specialty $model,array $data):Specialty{return $this->repo->update($model,$data);}public function delete(Specialty $model):void{$this->repo->delete($model);}}
