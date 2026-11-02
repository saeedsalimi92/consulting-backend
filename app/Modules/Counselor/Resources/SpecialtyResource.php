<?php
namespace App\Modules\Counselor\Resources;
use Illuminate\Http\Request;use Illuminate\Http\Resources\Json\JsonResource;
class SpecialtyResource extends JsonResource {public function toArray(Request $request):array{return ['id'=>$this->id,'title'=>$this->title,'slug'=>$this->slug,'is_active'=>$this->is_active];}}
