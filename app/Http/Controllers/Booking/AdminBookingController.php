<?php
namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BookingSlots;
use App\Services\BookingResponse;
use App\Services\BookingMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminBookingController extends Controller
{
    public function services() { return response()->json(['data'=>DB::table('booking_services')->orderByDesc('id')->paginate(30)]); }
    public function serviceStore(Request $r) {
        $v=$r->validate(['title'=>'required|string|max:255','duration_minutes'=>'required|integer|min:15|max:480',
            'price_irr'=>'required|integer|min:1|max:1000000000','deposit_irr'=>'required|integer|min:0|lte:price_irr','is_active'=>'sometimes|boolean']);
        $id=DB::table('booking_services')->insertGetId([...$v,'is_active'=>$v['is_active']??true,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('booking_services')->find($id)],201);
    }
    public function serviceUpdate(Request $r,int $service) {
        $v=$r->validate(['title'=>'sometimes|required|string|max:255','duration_minutes'=>'sometimes|required|integer|min:15|max:480',
            'price_irr'=>'sometimes|required|integer|min:1|max:1000000000','deposit_irr'=>'sometimes|required|integer|min:0','is_active'=>'sometimes|boolean']);
        $current=DB::table('booking_services')->find($service); abort_unless($current,404);
        if(($v['deposit_irr']??$current->deposit_irr)>($v['price_irr']??$current->price_irr)) throw ValidationException::withMessages(['deposit_irr'=>'Deposit exceeds price']);
        DB::table('booking_services')->where('id',$service)->update([...$v,'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('booking_services')->find($service)]);
    }
    public function assign(Request $r,int $counselor) {
        abort_unless(DB::table('counselors')->where('id',$counselor)->exists(),404);
        $v=$r->validate(['service_id'=>'required|exists:booking_services,id','price_irr'=>'nullable|integer|min:1|max:1000000000',
            'commission_type'=>'required|in:percentage,fixed','commission_value'=>'required|numeric|min:0']);
        $base=(int)($v['price_irr']??DB::table('booking_services')->where('id',$v['service_id'])->value('price_irr'));
        BookingMoney::commission($base,$v['commission_type'],(string)$v['commission_value']);
        DB::transaction(function()use($counselor,$v) {
            DB::table('counselors')->where('id',$counselor)->lockForUpdate()->first();
            DB::table('counselor_booking_services')->updateOrInsert(['counselor_id'=>$counselor,'booking_service_id'=>$v['service_id']],
                ['price_irr'=>$v['price_irr']??null,'commission_type'=>$v['commission_type'],'commission_value'=>$v['commission_value']]);
        },3);
        DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'action'=>'counselor.service_assigned','details'=>json_encode(['counselor_id'=>$counselor,'service_id'=>$v['service_id'],'price_irr'=>$v['price_irr']??null,'commission_type'=>$v['commission_type'],'commission_value'=>$v['commission_value']]),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('counselor_booking_services')->where('counselor_id',$counselor)->get()]);
    }
    public function unassign(Request $r,int $counselor,int $service){return DB::transaction(function()use($r,$counselor,$service){$row=DB::table('counselors')->where('id',$counselor)->lockForUpdate()->first();abort_unless($row,404);$assignment=DB::table('counselor_booking_services')->where('counselor_id',$counselor)->where('booking_service_id',$service)->first();abort_unless($assignment,404);abort_if(DB::table('bookings')->where('counselor_id',$counselor)->where('booking_service_id',$service)->whereIn('status',['held','confirmed','checked_in','in_progress'])->where('starts_at','>',now()->utc())->exists(),409,'Service has upcoming bookings; retain the association until they are resolved.');DB::table('counselor_booking_services')->where('counselor_id',$counselor)->where('booking_service_id',$service)->delete();DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'action'=>'counselor.service_unassigned','details'=>json_encode(['counselor_id'=>$counselor,'service_id'=>$service]),'created_at'=>now(),'updated_at'=>now()]);return response()->noContent();},3);}
    public function account(Request $r,int $counselor) {
        abort_unless(DB::table('counselors')->where('id',$counselor)->exists(),404);
        $v=$r->validate(['user_id'=>'required|integer|exists:users,id']);
        DB::transaction(function()use($r,$counselor,$v) {
            DB::table('counselors')->where('id',$counselor)->lockForUpdate()->first();
            abort_if(DB::table('counselors')->where('user_id',$v['user_id'])->where('id','<>',$counselor)->exists(),409,'User already assigned');
            DB::table('counselors')->where('id',$counselor)->update(['user_id'=>$v['user_id'],'updated_at'=>now()]);
            DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'action'=>'counselor.account_linked',
                'details'=>json_encode(['counselor_id'=>$counselor,'user_id'=>$v['user_id']]),'created_at'=>now(),'updated_at'=>now()]);
        },3);
        return response()->json(['data'=>DB::table('counselors')->where('id',$counselor)->first(['id','name','user_id'])]);
    }
    public function shifts(Request $r,int $counselor) {
        abort_unless(DB::table('counselors')->where('id',$counselor)->exists(),404);
        return response()->json(['data'=>DB::table('counselor_working_hours')->where('counselor_id',$counselor)->orderBy('weekday')->orderBy('starts_at')->get()]);
    }
    public function shiftStore(Request $r,int $counselor) {
        abort_unless(DB::table('counselors')->where('id',$counselor)->exists(),404);
        $v=$r->validate(['weekday'=>'required|integer|between:0,6','starts_at'=>'required|date_format:H:i','ends_at'=>'required|date_format:H:i|after:starts_at']);
        $id=DB::transaction(function()use($counselor,$v) {
            DB::table('counselors')->where('id',$counselor)->lockForUpdate()->first();
            $overlap=DB::table('counselor_working_hours')->where('counselor_id',$counselor)->where('weekday',$v['weekday'])->where('is_active',true)
                ->where('starts_at','<',$v['ends_at'])->where('ends_at','>',$v['starts_at'])->exists();
            if($overlap) throw ValidationException::withMessages(['starts_at'=>'Shift overlaps existing shift']);
            return DB::table('counselor_working_hours')->insertGetId([...$v,'counselor_id'=>$counselor,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        },3);
        return response()->json(['data'=>DB::table('counselor_working_hours')->find($id)],201);
    }
    public function shiftUpdate(Request $r,int $shift) {
        $v=$r->validate(['is_active'=>'required|boolean']);
        abort_unless(DB::table('counselor_working_hours')->where('id',$shift)->update([...$v,'updated_at'=>now()]),404);
        return response()->json(['data'=>DB::table('counselor_working_hours')->find($shift)]);
    }
    public function timeOff(Request $r,int $counselor) {
        abort_unless(DB::table('counselors')->where('id',$counselor)->exists(),404);
        return response()->json(['data'=>DB::table('counselor_time_off')->where('counselor_id',$counselor)->orderByDesc('starts_at')->paginate(30)]);
    }
    public function timeOffStore(Request $r,int $counselor) {
        abort_unless(DB::table('counselors')->where('id',$counselor)->exists(),404);
        $v=$r->validate(['starts_at'=>'required|date_format:Y-m-d\TH:i:sP','ends_at'=>'required|date_format:Y-m-d\TH:i:sP','reason'=>'nullable|string|max:255']);
        $start=Carbon::parse($v['starts_at'])->utc(); $end=Carbon::parse($v['ends_at'])->utc();
        if($end->lte($start)) throw ValidationException::withMessages(['ends_at'=>'Must follow start']);
        $id=DB::transaction(function()use($counselor,$start,$end,$v) {
            DB::table('counselors')->where('id',$counselor)->lockForUpdate()->first();
            $conflict=DB::table('bookings')->where('counselor_id',$counselor)
                ->where('starts_at','<',$end->toDateTimeString())->where('ends_at','>',$start->toDateTimeString())
                ->where(fn($q)=>$q->whereIn('status',['confirmed','completed'])
                    ->orWhere(fn($q)=>$q->where('status','held')->where('hold_expires_at','>',now()->utc()->toDateTimeString())))->exists();
            if($conflict) throw ValidationException::withMessages(['starts_at'=>'Reschedule existing bookings before time off']);
            return DB::table('counselor_time_off')->insertGetId(['counselor_id'=>$counselor,'starts_at'=>$start->toDateTimeString(),
                'ends_at'=>$end->toDateTimeString(),'reason'=>$v['reason']??null,'created_at'=>now(),'updated_at'=>now()]);
        },3);
        return response()->json(['data'=>DB::table('counselor_time_off')->find($id)],201);
    }
    public function timeOffDelete(int $id) { abort_unless(DB::table('counselor_time_off')->where('id',$id)->delete(),404); return response()->noContent(); }
    public function calendar(Request $r,BookingSlots $slots) {
        $v=$r->validate(['date'=>'required|date_format:Y-m-d','view'=>'nullable|in:day,week,month',
            'counselor_id'=>'nullable|integer|exists:counselors,id','service_id'=>'nullable|integer|exists:booking_services,id',
            'room_id'=>'nullable|integer|exists:appointment_rooms,id','client_id'=>'nullable|integer|exists:users,id','status'=>'nullable|in:held,confirmed,checked_in,in_progress,completed,cancelled,expired,no_show','q'=>'nullable|string|max:120']);
        $day=Carbon::createFromFormat('!Y-m-d',$v['date'],BookingSlots::TIMEZONE);
        $from=$day->copy(); $to=$day->copy()->addDay();
        if(($v['view']??'day')==='week') {$from=$day->copy()->startOfWeek(Carbon::MONDAY);$to=$from->copy()->addWeek();}
        if(($v['view']??'day')==='month') {$from=$day->copy()->startOfMonth();$to=$from->copy()->addMonth();}
        $query=DB::table('bookings as b')->join('users as u','u.id','=','b.user_id')->join('counselors as c','c.id','=','b.counselor_id')
            ->join('booking_services as s','s.id','=','b.booking_service_id')->leftJoin('appointment_rooms as r','r.id','=','b.room_id')->select('b.*','u.name as client_name','u.phone as client_phone',
                'c.name as counselor_name','s.title as service_name','r.name as room_name')
            ->where('b.starts_at','>=',$from->copy()->utc()->toDateTimeString())->where('b.starts_at','<',$to->copy()->utc()->toDateTimeString())
            ->when($v['counselor_id']??null,fn($q,$id)=>$q->where('b.counselor_id',$id))
            ->when($v['service_id']??null,fn($q,$id)=>$q->where('b.booking_service_id',$id))
            ->when($v['room_id']??null,fn($q,$id)=>$q->where('b.room_id',$id))
            ->when($v['client_id']??null,fn($q,$id)=>$q->where('b.user_id',$id))
            ->when($v['status']??null,fn($q,$status)=>$q->where('b.status',$status));
        if(!empty($v['q'])) $query->where(fn($q)=>$q->where('u.name','like','%'.$v['q'].'%')->orWhere('u.phone','like','%'.$v['q'].'%')
            ->orWhere('c.name','like','%'.$v['q'].'%')->orWhere('b.id',(int)$v['q']));
        $bookings=$query->orderBy('b.starts_at')->get();
        $free=[];
        if(isset($v['counselor_id'],$v['service_id'])) $free=$slots->slots($v['counselor_id'],$slots->service($v['counselor_id'],$v['service_id']),$v['date']);
        return response()->json(['data'=>$bookings->map(fn($booking)=>BookingResponse::present($booking)),
            'slots'=>$free,'timezone'=>BookingSlots::TIMEZONE,'range'=>['from'=>$from->toDateString(),'to'=>$to->copy()->subDay()->toDateString(),'view'=>$v['view']??'day']]);
    }
    public function clients(Request $r) {
        $v=$r->validate(['q'=>'required|string|min:2|max:100']);
        return response()->json(['data'=>User::query()->where('name','like','%'.$v['q'].'%')->orWhere('phone','like','%'.$v['q'].'%')
            ->select('id','name','email','phone')->limit(20)->get()]);
    }
    public function clientStore(Request $r) {
        $v=$r->validate(['name'=>'required|string|max:255','email'=>'required|email|unique:users,email','phone'=>'required|string|max:30|unique:users,phone']);
        $user=User::create([...$v,'password'=>bin2hex(random_bytes(32))]);
        return response()->json(['data'=>$user->only('id','name','email','phone')],201);
    }
}
