<?php
namespace App\Modules\Counselor\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\SoftDeletes;use Illuminate\Database\Eloquent\Relations\{BelongsToMany,MorphMany};
class Counselor extends Model {use SoftDeletes;protected $guarded=['id'];protected function casts():array{return ['is_active'=>'boolean','is_public'=>'boolean','languages'=>'array'];}public function specialties():BelongsToMany{return $this->belongsToMany(Specialty::class);}public function comments():MorphMany{return $this->morphMany(Comment::class,'commentable');}}
