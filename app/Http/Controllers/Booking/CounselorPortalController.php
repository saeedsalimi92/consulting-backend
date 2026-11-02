<?php
namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Services\BookingResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Services\BookingSlots;
use Illuminate\Validation\ValidationException;

class CounselorPortalController extends Controller
{
    private function counselorId(Request $request): int
    {
        return (int) DB::table('counselors')->where('user_id', $request->user()->id)->value('id');
    }

    public function appointments(Request $request)
    {
        $id=$this->counselorId($request);
        $query=DB::table('bookings as b')->join('users as u','u.id','=','b.user_id')
            ->join('booking_services as s','s.id','=','b.booking_service_id')->where('b.counselor_id',$id)
            ->select('b.*','u.name as client_name','u.phone as client_phone','s.title as service_name');
        if($request->filled('from')) $query->where('b.starts_at','>=',$request->date('from')->utc()->toDateTimeString());
        if($request->filled('to')) $query->where('b.starts_at','<=',$request->date('to')->utc()->toDateTimeString());
        $page=$query->orderBy('b.starts_at')->paginate(30);
        $page->getCollection()->transform(fn($row)=>BookingResponse::present($row));
        return response()->json(['data'=>$page]);
    }

    public function status(Request $request,int $booking)
    {
        $v=$request->validate(['status'=>'required|in:in_progress,completed,no_show']);
        $id=$this->counselorId($request);
        return DB::transaction(function() use($request,$booking,$id,$v) {
            $row=DB::table('bookings')->where('id',$booking)->where('counselor_id',$id)->lockForUpdate()->first();
            abort_unless($row,404);
            $allowed=match($v['status']) {'in_progress'=>['checked_in'],'completed'=>['confirmed','checked_in','in_progress'],'no_show'=>['confirmed'],default=>[]};
            abort_unless(in_array($row->status,$allowed,true),409);
            $timestamps=match($v['status']) {'in_progress'=>['session_started_at'=>now()],'completed'=>['session_completed_at'=>now()],default=>[]};
            DB::table('bookings')->where('id',$booking)->update(['status'=>$v['status'],...$timestamps,'updated_at'=>now()]);
            DB::table('booking_audit_events')->insert(['actor_id'=>$request->user()->id,'booking_id'=>$booking,
                'action'=>'session.'.$v['status'],'details'=>'{}','created_at'=>now(),'updated_at'=>now()]);
            return response()->json(['data'=>DB::table('bookings')->find($booking)]);
        },3);
    }

    public function note(Request $request,int $booking)
    {
        $id=$this->counselorId($request);
        abort_unless(DB::table('bookings')->where('id',$booking)->where('counselor_id',$id)->exists(),404);
        if($request->isMethod('get')) return response()->json(['data'=>DB::table('counselor_session_notes')->where('booking_id',$booking)
            ->where('counselor_id',$id)->orderByDesc('id')->get()]);
        $v=$request->validate(['body'=>'required|string|max:20000']);
        $note=DB::table('counselor_session_notes')->insertGetId(['booking_id'=>$booking,'counselor_id'=>$id,
            'author_id'=>$request->user()->id,'body'=>$v['body'],'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('counselor_session_notes')->find($note)],201);
    }

    public function finances(Request $request)
    {
        $id=$this->counselorId($request);
        $entries=DB::table('booking_ledger_entries as l')->join('bookings as b','b.id','=','l.booking_id')
            ->where('b.counselor_id',$id)->where('l.account','counselor_payable');
        $earned=(int)(clone $entries)->where('l.direction','credit')->sum('l.amount_irr');
        $reversed=(int)(clone $entries)->where('l.direction','debit')->sum('l.amount_irr');
        $settled=(int)DB::table('booking_settlements')->where('counselor_id',$id)->sum('amount_irr');
        return response()->json(['data'=>['earned_irr'=>$earned,'reversed_irr'=>$reversed,'settled_irr'=>$settled,
            'payable_irr'=>max(0,$earned-$reversed-$settled),'settlements'=>DB::table('booking_settlements')->where('counselor_id',$id)->orderByDesc('id')->paginate(20)]]);
    }

    public function sessionDetails(Request $request,int $booking,\App\Services\BookingNotices $notices)
    {
        $id=$this->counselorId($request);
        $v=$request->validate(['session_mode'=>'required|in:in_person,meet,voice_call','connection'=>'nullable|string|max:2000','instructions'=>'nullable|string|max:4000']);
        $row=DB::table('bookings')->where('id',$booking)->where('counselor_id',$id)->first(); abort_unless($row,404);
        if($v['session_mode']==='meet' && $v['connection']) abort_unless(parse_url($v['connection'],PHP_URL_SCHEME)==='https'
            && in_array(parse_url($v['connection'],PHP_URL_HOST),['google.com','meet.google.com'],true),422,'Invalid Meet URL');
        if($v['session_mode']==='voice_call' && $v['connection']) abort_unless(preg_match('/^[+0-9()\-\s]{7,30}$/',$v['connection']),422,'Invalid phone number');
        $notices->invalidateConnection($booking);
        DB::table('bookings')->where('id',$booking)->update(['session_mode'=>$v['session_mode'],'session_connection'=>$v['connection']??null,
            'session_instructions'=>$v['instructions']??null,'connection_released_at'=>null,'connection_release_notified_at'=>null,'updated_at'=>now()]);
        DB::table('booking_audit_events')->insert(['actor_id'=>$request->user()->id,'booking_id'=>$booking,'action'=>'session.connection_updated',
            'details'=>json_encode(['mode'=>$v['session_mode'],'has_connection'=>!empty($v['connection'])]),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>['id'=>$booking,'session_mode'=>$v['session_mode'],'connection_set'=>!empty($v['connection'])]]);
    }

    public function availability(Request $request)
    {
        $id=$this->counselorId($request);
        return response()->json(['data'=>['shifts'=>DB::table('counselor_working_hours')->where('counselor_id',$id)->orderBy('weekday')->orderBy('starts_at')->get(),
            'time_off'=>DB::table('counselor_time_off')->where('counselor_id',$id)->where('ends_at','>=',now()->utc())->orderBy('starts_at')->paginate(30)]]);
    }

    public function timeOff(Request $request)
    {
        $id=$this->counselorId($request);
        $v=$request->validate(['starts_at'=>'required|date_format:Y-m-d\TH:i:sP','ends_at'=>'required|date_format:Y-m-d\TH:i:sP','reason'=>'nullable|string|max:255']);
        $start=Carbon::parse($v['starts_at'])->utc(); $end=Carbon::parse($v['ends_at'])->utc();
        if($end->lte($start)) throw ValidationException::withMessages(['ends_at'=>'Must follow start']);
        return DB::transaction(function()use($request,$id,$v,$start,$end) {
            DB::table('counselors')->where('id',$id)->lockForUpdate()->first();
            abort_if(DB::table('bookings')->where('counselor_id',$id)->where('starts_at','<',$end)->where('ends_at','>',$start)
                ->whereIn('status',['held','confirmed'])->exists(),409,'Reschedule existing appointments first');
            $item=DB::table('counselor_time_off')->insertGetId(['counselor_id'=>$id,'starts_at'=>$start->toDateTimeString(),
                'ends_at'=>$end->toDateTimeString(),'reason'=>$v['reason']??null,'created_at'=>now(),'updated_at'=>now()]);
            return response()->json(['data'=>DB::table('counselor_time_off')->find($item)],201);
        },3);
    }
}
