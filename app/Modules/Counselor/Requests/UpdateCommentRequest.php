<?php
namespace App\Modules\Counselor\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdateCommentRequest extends FormRequest {public function authorize():bool{$m=\App\Modules\Counselor\Models\Comment::find($this->route('comment'));return $m && ($this->user()?->can('update',$m)??false);}public function rules():array{return ['body'=>'sometimes|required|string|max:5000','is_approved'=>'sometimes|boolean'];}}
