<?php
namespace App\Modules\Counselor\Requests;
use Illuminate\Foundation\Http\FormRequest;
class IndexCounselorRequest extends FormRequest {public function authorize():bool{return true;}public function rules():array{return ['active'=>'sometimes|boolean','specialty_id'=>'sometimes|integer|exists:specialties,id','search'=>'sometimes|string|max:255','per_page'=>'sometimes|integer|min:1|max:100'];}}
