<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function centerProfile(Request $r){$key='center_profile';if($r->isMethod('get'))return response()->json(['data'=>json_decode(DB::table('cms_settings')->where('key',$key)->value('value')?:'{}',true)]);
        $v=$r->validate(['name'=>'sometimes|required|string|max:180','phone'=>'nullable|string|max:30','email'=>'nullable|email|max:255','address'=>'nullable|string|max:2000','website'=>'nullable|url|max:255','logo_url'=>'nullable|url|max:2048','timezone'=>'sometimes|in:Asia/Tehran']);
        $current=json_decode(DB::table('cms_settings')->where('key',$key)->value('value')?:'{}',true)?:[];$data=array_replace($current,$v);$this->save($key,$data,$r);return response()->json(['data'=>$data]);}
    public function operatingHours(Request $r){if($r->isMethod('get'))return response()->json(['timezone'=>'Asia/Tehran','data'=>DB::table('center_operating_hours')->orderBy('weekday')->get()]);
        $v=$r->validate(['weekday'=>'required|integer|between:0,6','is_open'=>'required|boolean','opens_at'=>'nullable|date_format:H:i','closes_at'=>'nullable|date_format:H:i|after:opens_at']);
        abort_if($v['is_open']&&(!$v['opens_at']||!$v['closes_at']),422,'Open days require opening and closing times.');
        $future=DB::table('bookings')->whereIn('status',['held','confirmed','checked_in','in_progress'])->where('starts_at','>=',now()->utc())->where('starts_at','<',now()->utc()->addYear())->get(['starts_at','ends_at']);
        foreach($future as $booking){$start=\Illuminate\Support\Carbon::parse($booking->starts_at,'UTC')->setTimezone('Asia/Tehran');if($start->dayOfWeek!==(int)$v['weekday'])continue;$end=\Illuminate\Support\Carbon::parse($booking->ends_at,'UTC')->setTimezone('Asia/Tehran');abort_if(!$v['is_open']||$start->format('H:i')<$v['opens_at']||$end->format('H:i')>$v['closes_at'],409,'Operating-hour change conflicts with existing appointments.');}
        DB::table('center_operating_hours')->updateOrInsert(['weekday'=>$v['weekday']], [...$v,'updated_at'=>now(),'created_at'=>now()]);$this->audit($r,'center.operating_hours_updated',['weekday'=>$v['weekday']]);return response()->json(['data'=>DB::table('center_operating_hours')->where('weekday',$v['weekday'])->first()]);}
    public function sms(Request $r){$key='booking.sms_driver';if($r->isMethod('get'))return response()->json(['data'=>['driver'=>DB::table('cms_settings')->where('key',$key)->value('value')?:'log','real_delivery_enabled'=>false,'sms_ir_http_adapter_ready'=>false,'fake_available'=>app()->environment(['local','testing'])]]);
        $v=$r->validate(['driver'=>'required|in:log,fake,sms_ir']);if($v['driver']==='fake')abort_unless(app()->environment(['local','testing']),422,'Fake SMS is only available locally.');
        $this->save($key,$v['driver'],$r);config(['booking.sms_driver'=>$v['driver']]);return $this->sms($r);}
    public function seo(Request $r){$key='cms_seo_defaults';if($r->isMethod('get'))return response()->json(['data'=>json_decode(DB::table('cms_settings')->where('key',$key)->value('value')?:'{}',true)]);
        $v=$r->validate(['title'=>'nullable|string|max:255','description'=>'nullable|string|max:500','canonical_base'=>'nullable|url|max:255','og_image'=>'nullable|url|max:2048']);$current=json_decode(DB::table('cms_settings')->where('key',$key)->value('value')?:'{}',true)?:[];$data=array_replace($current,$v);$this->save($key,$data,$r);return response()->json(['data'=>$data]);}
    private function save(string $key,mixed $value,Request $r):void {DB::table('cms_settings')->updateOrInsert(['key'=>$key],['value'=>is_string($value)?$value:json_encode($value,JSON_UNESCAPED_UNICODE),'updated_at'=>now(),'created_at'=>now()]);$this->audit($r,'settings.updated',['key'=>$key]);}
    private function audit(Request $r,string $action,array $details):void {DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'action'=>$action,'details'=>json_encode($details),'created_at'=>now(),'updated_at'=>now()]);}
}
