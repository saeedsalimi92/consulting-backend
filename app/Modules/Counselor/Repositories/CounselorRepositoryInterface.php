<?php
namespace App\Modules\Counselor\Repositories;
use App\Modules\Counselor\Models\Counselor;use Illuminate\Contracts\Pagination\LengthAwarePaginator;
interface CounselorRepositoryInterface {public function paginate(array $filters=[]):LengthAwarePaginator;public function find(int $id):?Counselor;public function create(array $data):Counselor;public function update(Counselor $model,array $data):Counselor;public function delete(Counselor $model):void;}
