<?php
 namespace App\Modules\Counselor\Repositories;
 use App\Modules\Counselor\Models\Specialty;use Illuminate\Contracts\Pagination\LengthAwarePaginator;
 class SpecialtyRepository implements SpecialtyRepositoryInterface {public function paginate(array $f=[]):LengthAwarePaginator{$q=Specialty::query();if(array_key_exists('active',$f))$q->where('is_active',$f['active']);return $q->orderBy('title')->paginate(min((int)($f['per_page']??15),100));}public function find(int $id):?Specialty{return Specialty::find($id);}public function create(array $data):Specialty{return Specialty::create($data);}public function update(Specialty $model,array $data):Specialty{$model->update($data);return $model->refresh();}public function delete(Specialty $model):void{$model->delete();}}
