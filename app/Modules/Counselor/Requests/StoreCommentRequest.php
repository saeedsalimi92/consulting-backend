<?php
namespace App\Modules\Counselor\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreCommentRequest extends FormRequest {public function authorize():bool{return $this->user()?->can('create',\App\Modules\Counselor\Models\Comment::class)??false;}public function rules():array{return ['type'=>'required|in:counselor','id'=>'required|integer|exists:counselors,id','body'=>'required|string|max:5000','parent_id'=>'nullable|integer|exists:comments,id'];}}
