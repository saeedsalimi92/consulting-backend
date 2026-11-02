<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;
class CmsEntry extends Model {protected $fillable=['type','title','slug','body','image','extra','is_active','status','published_at','seo_title','seo_description','sort_order'];protected function casts():array{return ['extra'=>'array','is_active'=>'boolean','published_at'=>'datetime','sort_order'=>'integer'];}}
