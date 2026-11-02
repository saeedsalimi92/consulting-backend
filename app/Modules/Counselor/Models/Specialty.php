<?php
namespace App\Modules\Counselor\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class Specialty extends Model {protected $fillable=['title','slug','is_active'];protected function casts():array{return ['is_active'=>'boolean'];}public function counselors():BelongsToMany{return $this->belongsToMany(Counselor::class);}}
