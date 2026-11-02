<?php
namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverSmsReminder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SmsReminderController extends Controller
{
    public function rules(Request $r) {
        if($r->isMethod('get')) {$page=$r->validate(['per_page'=>'nullable|integer|min:1|max:100']);$rows=DB::table('booking_sms_reminder_rules')->orderBy('id')->paginate($page['per_page']??30)->through(fn($row)=>$this->presentRule($row));return response()->json(['data'=>$rows]);}
        $v=$r->validate(['name'=>'required|string|max:120','timing_type'=>'required|in:relative,fixed_local',
            'minutes_before'=>'required_if:timing_type,relative|nullable|integer|min:0|max:43200',
            'local_time'=>'required_if:timing_type,fixed_local|nullable|date_format:H:i',
            'recipients'=>'required|array|min:1','recipients.*'=>'required|in:client,counselor,reception',
            'modes'=>'nullable|array','modes.*'=>'in:in_person,meet,voice_call','template'=>'required|string|max:1000',
            'quiet_start'=>'nullable|date_format:H:i','quiet_end'=>'nullable|date_format:H:i',
            'max_attempts'=>'sometimes|integer|min:1|max:8','retry_delay_seconds'=>'sometimes|integer|min:30|max:86400','is_active'=>'sometimes|boolean']);
        if(($v['quiet_start']??null) xor ($v['quiet_end']??null)) abort(422,'Quiet hours require both start and end.');
        if($v['timing_type']==='relative' && !array_key_exists('minutes_before',$v)) abort(422,'minutes_before is required for relative rules.');
        $id=DB::table('booking_sms_reminder_rules')->insertGetId([...$v,'recipients'=>json_encode(array_values(array_unique($v['recipients']))),
            'modes'=>isset($v['modes'])?json_encode($v['modes']):null,'is_active'=>$v['is_active']??false,
            'max_attempts'=>$v['max_attempts']??3,'retry_delay_seconds'=>$v['retry_delay_seconds']??300,'created_at'=>now(),'updated_at'=>now()]);
        $this->audit($r,'sms_reminder_rule.created',['rule_id'=>$id]);
        return response()->json(['data'=>$this->presentRule(DB::table('booking_sms_reminder_rules')->find($id))],201);
    }
    public function update(Request $r,int $rule) {
        $row=DB::table('booking_sms_reminder_rules')->find($rule); abort_unless($row,404);
        $v=$r->validate(['name'=>'sometimes|required|string|max:120','timing_type'=>'sometimes|in:relative,fixed_local',
            'minutes_before'=>'nullable|integer|min:0|max:43200','local_time'=>'nullable|date_format:H:i',
            'recipients'=>'sometimes|required|array|min:1','recipients.*'=>'in:client,counselor,reception','modes'=>'nullable|array','modes.*'=>'in:in_person,meet,voice_call',
            'template'=>'sometimes|required|string|max:1000','quiet_start'=>'nullable|date_format:H:i','quiet_end'=>'nullable|date_format:H:i',
            'max_attempts'=>'sometimes|integer|min:1|max:8','retry_delay_seconds'=>'sometimes|integer|min:30|max:86400','is_active'=>'sometimes|boolean']);
        $effectiveType=$v['timing_type']??$row->timing_type;
        abort_if($effectiveType==='relative'&&($v['minutes_before']??$row->minutes_before)===null,422,'Relative rules require minutes_before.');
        abort_if($effectiveType==='fixed_local'&&empty($v['local_time']??$row->local_time),422,'Fixed-local rules require local_time.');
        $quietStart=$v['quiet_start']??$row->quiet_start;$quietEnd=$v['quiet_end']??$row->quiet_end;
        abort_if((bool)$quietStart !== (bool)$quietEnd,422,'Quiet hours require both start and end.');
        if(array_key_exists('recipients',$v))$v['recipients']=json_encode(array_values(array_unique($v['recipients'])));
        if(array_key_exists('modes',$v))$v['modes']=$v['modes']?json_encode($v['modes']):null;
        DB::table('booking_sms_reminder_rules')->where('id',$rule)->update([...$v,'updated_at'=>now()]);
        $this->audit($r,'sms_reminder_rule.updated',['rule_id'=>$rule,'fields'=>array_keys($v)]);
        return response()->json(['data'=>$this->presentRule(DB::table('booking_sms_reminder_rules')->find($rule))]);
    }
    public function destroy(Request $r,int $rule) {
        abort_unless(DB::table('booking_sms_reminder_rules')->where('id',$rule)->exists(),404);
        abort_if(DB::table('booking_sms_reminders')->where('rule_id',$rule)->exists(),409,'Rule has delivery history; disable it instead.');
        DB::table('booking_sms_reminder_rules')->delete($rule); $this->audit($r,'sms_reminder_rule.deleted',['rule_id'=>$rule]); return response()->noContent();
    }
    public function scheduled(Request $r) { $v=$r->validate(['status'=>'nullable|in:pending,queued,sending,simulated,sent,failed,cancelled,skipped,not_configured','booking_id'=>'nullable|integer','per_page'=>'nullable|integer|min:1|max:100']);
        $q=DB::table('booking_sms_reminders')->when($v['status']??null,fn($q,$s)=>$q->where('status',$s))->when($v['booking_id']??null,fn($q,$id)=>$q->where('booking_id',$id))->orderBy('scheduled_at');
        return response()->json(['data'=>$q->paginate($v['per_page']??30)]); }
    public function history(Request $r) { $v=$r->validate(['status'=>'nullable|string|max:20','rule_id'=>'nullable|integer','per_page'=>'nullable|integer|min:1|max:100']);
        return response()->json(['data'=>DB::table('booking_sms_reminders')->when($v['status']??null,fn($q,$s)=>$q->where('status',$s))->when($v['rule_id']??null,fn($q,$id)=>$q->where('rule_id',$id))->orderByDesc('id')->paginate($v['per_page']??30)]); }
    public function attempts(Request $r) { $v=$r->validate(['reminder_id'=>'required|integer|exists:booking_sms_reminders,id','per_page'=>'nullable|integer|min:1|max:100']);return response()->json(['data'=>DB::table('booking_sms_delivery_attempts')->where('reminder_id',$v['reminder_id'])->orderByDesc('attempt_number')->paginate($v['per_page']??30)]); }
    public function preview(Request $r,int $rule) { $v=$r->validate(['booking_id'=>'required|integer|exists:bookings,id','recipient_type'=>'sometimes|in:client,counselor,reception']);$row=DB::table('booking_sms_reminder_rules')->find($rule);abort_unless($row,404);$b=DB::table('bookings')->find($v['booking_id']);$u=DB::table('users')->find($v['recipient_type']==='counselor'?(DB::table('counselors')->where('id',$b->counselor_id)->value('user_id')??$b->user_id):$b->user_id);$local=\Illuminate\Support\Carbon::parse($b->starts_at,'UTC')->setTimezone('Asia/Tehran');$text=strtr($row->template,['{{client_name}}'=>$u?->name??'مراجع','{{counselor_name}}'=>DB::table('counselors')->where('id',$b->counselor_id)->value('name')??'','{{appointment_date}}'=>$local->format('Y-m-d'),'{{appointment_time}}'=>$local->format('H:i'),'{{session_type}}'=>$b->session_mode,'{{center_name}}'=>config('app.name'),'{{booking_reference}}'=>'BK-'.$b->id,'{{center_address}}'=>'','{{service_name}}'=>DB::table('booking_services')->where('id',$b->booking_service_id)->value('title')??'']);return response()->json(['data'=>['rule_id'=>$rule,'booking_id'=>$b->id,'timezone'=>'Asia/Tehran','message'=>$text]]); }
    public function retry(Request $r,int $reminder) { DB::transaction(function()use($reminder){$row=DB::table('booking_sms_reminders')->where('id',$reminder)->lockForUpdate()->first(); abort_unless($row,404);
        abort_unless(in_array($row->status,['failed','not_configured'],true),409,'Only failed or unconfigured reminders can be retried.');
        DB::table('booking_sms_reminders')->where('id',$reminder)->update(['status'=>'queued','available_at'=>null,'retry_attempts'=>0,'last_error'=>null,'updated_at'=>now()]);});
        DeliverSmsReminder::dispatch($reminder)->afterCommit(); $this->audit($r,'sms_reminder.retried',['reminder_id'=>$reminder]); return response()->json(['data'=>DB::table('booking_sms_reminders')->find($reminder)],202); }
    public function cancel(Request $r,int $reminder) { $changed=DB::table('booking_sms_reminders')->where('id',$reminder)->where('status','pending')->update(['status'=>'cancelled','updated_at'=>now()]); abort_unless($changed,409,'Only pending reminders can be cancelled.'); $this->audit($r,'sms_reminder.cancelled',['reminder_id'=>$reminder]); return response()->noContent(); }
    public function preference(Request $r) {
        if($r->isMethod('get')) return response()->json(['data'=>DB::table('user_notification_preferences')->where('user_id',$r->user()->id)->first()??['user_id'=>$r->user()->id,'sms_enabled'=>false,'sms_consent_at'=>null]]);
        $v=$r->validate(['sms_enabled'=>'required|boolean']); DB::table('user_notification_preferences')->updateOrInsert(['user_id'=>$r->user()->id],
            ['sms_enabled'=>$v['sms_enabled'],'sms_consent_at'=>$v['sms_enabled']?now():null,'updated_at'=>now(),'created_at'=>now()]);
        return response()->json(['data'=>DB::table('user_notification_preferences')->where('user_id',$r->user()->id)->first()]);
    }
    private function audit(Request $r,string $action,array $details):void { DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'action'=>$action,'details'=>json_encode($details),'created_at'=>now(),'updated_at'=>now()]); }
    private function presentRule(object $row):array { $data=(array)$row;$data['recipients']=json_decode($data['recipients']??'[]',true)?:[];$data['modes']=isset($data['modes'])?json_decode($data['modes'],true):null;return $data; }
}
