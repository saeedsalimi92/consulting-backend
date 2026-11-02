<?php
namespace App\Modules\Counselor\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\{MorphTo,BelongsTo,HasMany};
class Comment extends Model {protected $fillable=['commentable_type','commentable_id','user_id','guest_name','guest_email','body','parent_id','is_approved'];protected function casts():array{return ['is_approved'=>'boolean'];}public function commentable():MorphTo{return $this->morphTo();}public function parent():BelongsTo{return $this->belongsTo(self::class,'parent_id');}public function replies():HasMany{return $this->hasMany(self::class,'parent_id');}}
