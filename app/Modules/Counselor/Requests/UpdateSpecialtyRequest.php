<?php
namespace App\Modules\Counselor\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdateSpecialtyRequest extends FormRequest {public function authorize():bool{$m=\App\Modules\Counselor\Models\Specialty::find($this->route('specialty'));return $m && ($this->user()?->can('update',$m)??false);}public function rules():array{return ['title'=>'sometimes|required|string|max:255','slug'=>['sometimes','required','alpha_dash','max:255',\Illuminate\Validation\Rule::unique('specialties','slug')->ignore($this->route('specialty'))],'is_active'=>'sometimes|boolean'];}}
