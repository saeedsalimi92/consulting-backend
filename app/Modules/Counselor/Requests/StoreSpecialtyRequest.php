<?php
namespace App\Modules\Counselor\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreSpecialtyRequest extends FormRequest {public function authorize():bool{return $this->user()?->can('create',\App\Modules\Counselor\Models\Specialty::class)??false;}public function rules():array{return ['title'=>'required|string|max:255','slug'=>'required|alpha_dash|max:255|unique:specialties,slug','is_active'=>'sometimes|boolean'];}}
