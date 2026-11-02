<?php
namespace App\Console\Commands;
use App\Jobs\DeliverSmsReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScheduleSmsReminders extends Command
{
    protected $signature='booking:schedule-sms-reminders';
    protected $description='Recalculate configured SMS reminders and queue due deliveries';
    public function handle():int
    {
        DB::table('booking_sms_reminders')->whereIn('status',['pending','queued'])->whereIn('rule_id',DB::table('booking_sms_reminder_rules')->where('is_active',false)->select('id'))->update(['status'=>'cancelled','last_error'=>'Reminder rule disabled','updated_at'=>now()]);
        DB::table('booking_sms_reminders')->where('status','pending')->whereIn('booking_id',DB::table('bookings')->select('id')->whereNotIn('status',['confirmed','checked_in']))->update(['status'=>'cancelled','last_error'=>'Booking no longer active','updated_at'=>now()]);
        DB::table('booking_sms_reminders as r')->join('bookings as b','b.id','=','r.booking_id')->whereIn('r.status',['pending','queued'])
            ->whereColumn('r.appointment_starts_at','!=','b.starts_at')->update(['r.status'=>'cancelled','r.last_error'=>'Booking was rescheduled','r.updated_at'=>now()]);
        DB::table('booking_sms_reminders')->where('status','queued')->where('updated_at','<',now()->subMinutes(10))->update(['status'=>'pending','updated_at'=>now()]);
        $rules=DB::table('booking_sms_reminder_rules')->where('is_active',true)->get();
        DB::table('bookings')->whereIn('status',['confirmed','checked_in'])->where('starts_at','>',now()->utc())
            ->where('starts_at','<',now()->utc()->addDays(45))->orderBy('id')->chunkById(200,function($bookings)use($rules){
                foreach($bookings as $booking) foreach($rules as $rule) {
                    $mode=$booking->session_mode ?: ($booking->mode==='in_person'?'in_person':'meet');
                    if($rule->modes && !in_array($mode,json_decode($rule->modes,true)?:[],true))continue;
                    $start=Carbon::parse($booking->starts_at,'UTC')->setTimezone('Asia/Tehran');
                    $due=$rule->timing_type==='relative'?$start->copy()->subMinutes((int)$rule->minutes_before):$start->copy()->startOfDay()->setTimeFromTimeString($rule->local_time);
                    if($due->gte($start)||$due->lt(now('Asia/Tehran')->subDay()))continue;
                    if($rule->quiet_start && $rule->quiet_end) {
                        $clock=$due->format('H:i:s');$from=$rule->quiet_start;$to=$rule->quiet_end;
                        $quiet=$from<$to?($clock>=$from&&$clock<$to):($clock>=$from||$clock<$to);
                        if($quiet){$due=$due->copy()->setTimeFromTimeString($to);if($from>$to&&$clock>=$from)$due->addDay();}
                    }
                    // Quiet hours must never push a reminder past the appointment itself.
                    if($due->gte($start))continue;
                    foreach(json_decode($rule->recipients,true)?:[] as $recipient) foreach($this->recipientIds($recipient,$booking) as $userId) {
                        if(!DB::table('user_notification_preferences')->where('user_id',$userId)->where('sms_enabled',true)->exists())continue;
                        if(!DB::table('users')->where('id',$userId)->whereNotNull('phone')->exists())continue;
                        $attributes=['booking_id'=>$booking->id,'rule_id'=>$rule->id,'user_id'=>$userId,
                            'appointment_starts_at'=>$booking->starts_at,'scheduled_at'=>$due->copy()->utc(),'recipient_type'=>$recipient,
                            'status'=>'pending','created_at'=>now(),'updated_at'=>now()];
                        DB::table('booking_sms_reminders')->insertOrIgnore($attributes);
                        DB::table('booking_sms_reminders')->where('booking_id',$booking->id)->where('rule_id',$rule->id)->where('user_id',$userId)
                            ->where('appointment_starts_at',$booking->starts_at)->where('status','pending')->update(['scheduled_at'=>$due->copy()->utc(),'updated_at'=>now()]);
                    }
                }
            });
        $due=DB::table('booking_sms_reminders')->where('status','pending')->where('scheduled_at','<=',now()->utc())->where(fn($q)=>$q->whereNull('available_at')->orWhere('available_at','<=',now()->utc()))->orderBy('id')->limit(500)->pluck('id');
        foreach($due as $id) if(DB::table('booking_sms_reminders')->where('id',$id)->where('status','pending')->update(['status'=>'queued','updated_at'=>now()])) DeliverSmsReminder::dispatch((int)$id);
        return self::SUCCESS;
    }
    private function recipientIds(string $type,object $booking):array
    {
        if($type==='client')return [(int)$booking->user_id];
        if($type==='counselor'){$id=DB::table('counselors')->where('id',$booking->counselor_id)->value('user_id');return $id?[(int)$id]:[];}
        $ids=DB::table('users')->where('is_admin',true)->pluck('id')->all();
        foreach(DB::table('users')->whereNotNull('admin_permissions')->get(['id','admin_permissions']) as $user) if(in_array('appointments.manage',json_decode($user->admin_permissions?:'[]',true)?:[],true))$ids[]=$user->id;
        $ids=array_merge($ids,DB::table('admin_user_roles as ur')->join('admin_role_permissions as rp','rp.role_id','=','ur.role_id')->where('rp.permission','appointments.manage')->pluck('ur.user_id')->all());
        return array_values(array_unique(array_map('intval',$ids)));
    }
}
