<?php
namespace App\Modules\Counselor\Repositories;
use App\Modules\Counselor\Models\Specialty;use Illuminate\Contracts\Pagination\LengthAwarePaginator;
interface SpecialtyRepositoryInterface {public function paginate(array $filters=[]):LengthAwarePaginator;public function find(int $id):?Specialty;public function create(array $data):Specialty;public function update(Specialty $model,array $data):Specialty;public function delete(Specialty $model):void;}
