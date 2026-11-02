<?php
namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Services\BookingSlots;
use App\Services\BookingResponse;
use App\Services\BookingMoney;
use App\Services\BookingPricing;
use App\Services\BookingPolicy;
use App\Services\BookingNotices;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function services() { return response()->json(['data'=>DB::table('booking_services')->where('is_active',true)->orderBy('id')->get()]); }
    public function counselorServices(int $counselor) {
        abort_unless(DB::table('counselors')->where('id',$counselor)->where('is_active',true)->where('is_public',true)->whereNull('deleted_at')->exists(),404);
        return response()->json(['data'=>DB::table('counselor_booking_services as a')->join('booking_services as s','s.id','=','a.booking_service_id')
            ->where('a.counselor_id',$counselor)->where('s.is_active',true)->select('s.id as service_id','s.title','s.duration_minutes',
                's.deposit_irr',DB::raw('COALESCE(a.price_irr,s.price_irr) as price_irr'))->orderBy('s.title')->get()]);
    }

    public function availability(Request $r, BookingSlots $slots) {
        $v=$r->validate(['counselor_id'=>'required|integer|exists:counselors,id','date'=>'required|date_format:Y-m-d','service_id'=>'required|integer|exists:booking_services,id']);
        $service=$slots->service($v['counselor_id'],$v['service_id'],true);
        return response()->json(['timezone'=>BookingSlots::TIMEZONE,'slots'=>$slots->slots($v['counselor_id'],$service,$v['date'])]);
    }

    public function joinWaitlist(Request $r,BookingSlots $slots) {
        $v=$r->validate(['counselor_id'=>'required|integer|exists:counselors,id','service_id'=>'required|integer|exists:booking_services,id',
            'starts_at'=>'required|date_format:Y-m-d\TH:i:sP']);
        $start=Carbon::parse($v['starts_at'])->utc();
        $service=$slots->service((int)$v['counselor_id'],(int)$v['service_id'],true);
        $local=$start->copy()->setTimezone(BookingSlots::TIMEZONE);
        $matching=collect($slots->slots((int)$v['counselor_id'],$service,$local->toDateString()))
            ->first(fn($slot)=>Carbon::parse($slot['starts_at'])->equalTo($start));
        abort_unless($matching,422,'Slot is not in counselor availability');
        abort_if($matching['available'],409,'Slot is currently available');
        $id=DB::table('booking_waitlist')->insertOrIgnore(['user_id'=>$r->user()->id,'counselor_id'=>$v['counselor_id'],
            'booking_service_id'=>$v['service_id'],'starts_at'=>$start->toDateTimeString(),'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        $row=DB::table('booking_waitlist')->where('user_id',$r->user()->id)->where('counselor_id',$v['counselor_id'])
            ->where('booking_service_id',$v['service_id'])->where('starts_at',$start->toDateTimeString())->first();
        return response()->json(['data'=>$row],$id?201:200);
    }

    public function leaveWaitlist(Request $r,int $entry) {
        $row=DB::table('booking_waitlist')->where('id',$entry)->where('user_id',$r->user()->id)->first();abort_unless($row,404);
        abort_unless(in_array($row->status,['active','notified'],true),409,'Waitlist entry is no longer cancellable.');
        DB::table('booking_waitlist')->where('id',$entry)->update(['status'=>'cancelled','offer_expires_at'=>null,'updated_at'=>now()]);
        DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'action'=>'waitlist.left','details'=>json_encode(['waitlist_id'=>$entry]),'created_at'=>now(),'updated_at'=>now()]);
        return response()->noContent();
    }

    public function store(Request $r, BookingSlots $slots, \App\Services\PromoCodeRedemption $promos, \App\Services\Auth\ProfileCompleteness $profiles) {
        $v=$r->validate(['counselor_id'=>'required|integer|exists:counselors,id','service_id'=>'required|integer|exists:booking_services,id',
            'starts_at'=>'required|date_format:Y-m-d\TH:i:sP','mode'=>'required|in:online,in_person','session_mode'=>'nullable|in:in_person,meet,voice_call','payment_option'=>'required|in:full,deposit','promo_code'=>'nullable|string|max:64']);
        $start=Carbon::parse($v['starts_at'])->utc();
        $id=DB::transaction(function()use($r,$slots,$promos,$profiles,$v,$start) {
            $client=\App\Models\User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $profiles->assertComplete($client);
            // Lock the counselor row so requests serialize even when no bookings exist.
            $counselor=DB::table('counselors')->where('id',$v['counselor_id'])->lockForUpdate()->first();
            if(!$counselor) throw ValidationException::withMessages(['counselor_id'=>'Counselor unavailable']);
            $service=$slots->service($v['counselor_id'],$v['service_id'],true);
            $slots->requireAvailable($v['counselor_id'],$service,$start);
            $price=(int)$service->effective_price_irr;
            $promo=filled($v['promo_code']??null)?$promos->quote($v['promo_code'],$r->user()->id,$price):null;
            if($promo) $price=$promo['price_irr'];
            $pricing=BookingPricing::snapshot((int)$service->id,$price,$service->commission_type,(string)$service->commission_value,$v['payment_option'],(int)$service->deposit_irr);
            $commission=$pricing['commission_total_irr']; $required=$pricing['required_irr'];
            if($required<=0) throw ValidationException::withMessages(['payment_option'=>'Positive payment required']);
            $id=DB::table('bookings')->insertGetId(['user_id'=>$r->user()->id,'created_by'=>$r->user()->id,
                'counselor_id'=>$v['counselor_id'],'booking_service_id'=>$service->id,'mode'=>$v['mode'],
                'session_mode'=>$v['session_mode']??($v['mode']==='in_person'?'in_person':'meet'),
                'starts_at'=>$start->toDateTimeString(),'ends_at'=>$start->copy()->addMinutes($service->duration_minutes)->toDateTimeString(),
                'status'=>'held','payment_option'=>$v['payment_option'],'total_irr'=>$pricing['total_irr'],'required_irr'=>$required,
                'commission_total_irr'=>$commission,'duration_minutes'=>$service->duration_minutes,
                'tax_irr'=>$pricing['tax_irr'],'tax_rate_basis_points'=>$pricing['tax_rate_basis_points'],
                'base_price_irr'=>$pricing['base_price_irr'],'center_share_irr'=>$pricing['center_share_irr'],
                'promo_code_id'=>$promo['promo_code_id']??null,'discount_irr'=>$promo['discount_irr']??0,
                'hold_expires_at'=>now()->utc()->addMinutes((int)BookingPolicy::get('hold_minutes',config('booking.hold_minutes',20)))->toDateTimeString(),'created_at'=>now(),'updated_at'=>now()]);
            if($promo) $promos->record($promo['promo_code_id'],$id,$r->user()->id,$promo['discount_irr']);
            return $id;
        },3);
        return response()->json(['data'=>BookingResponse::present(DB::table('bookings')->find($id))],201);
    }

    public function mine(Request $r) {
        $page=DB::table('bookings')->where('user_id',$r->user()->id)->orderByDesc('starts_at')->paginate(20);
        $page->getCollection()->transform(fn($booking)=>BookingResponse::presentForClient($booking));
        return $page;
    }
    public function show(Request $r,int $booking) {
        $row=DB::table('bookings')->where('id',$booking)->where('user_id',$r->user()->id)->first();
        abort_unless($row,404);
        return response()->json(['data'=>BookingResponse::presentForClient($row)]);
    }
    public function notes(Request $r,int $booking) {
        abort_unless(DB::table('bookings')->where('id',$booking)->where('user_id',$r->user()->id)->exists(),404);
        return response()->json(['data'=>DB::table('booking_notes')->where('booking_id',$booking)->where('visible_to_client',true)
            ->select('id','body','created_at')->orderByDesc('id')->get()]);
    }
    public function cancelRequest(Request $r,int $booking) {
        $v=$r->validate(['reason'=>'nullable|string|max:2000']);
        return DB::transaction(function()use($r,$booking,$v) {
            $b=DB::table('bookings')->where('id',$booking)->lockForUpdate()->first();
            abort_unless($b && (int)$b->user_id===$r->user()->id,404);
            abort_unless(in_array($b->status,['held','confirmed'],true) && Carbon::parse($b->starts_at,'UTC')->isFuture(),422,'Cannot request cancellation');
            abort_if($b->status==='held' && $b->hold_expires_at<=now()->utc()->toDateTimeString(),409,'Reservation expired');
            $cutoff=max(0,(int)BookingPolicy::get('cancellation_cutoff_hours',config('booking.cancellation_cutoff_hours',0)));
            abort_if(Carbon::parse($b->starts_at,'UTC')->lte(now()->utc()->addHours($cutoff)),422,'Cancellation cutoff passed');
            abort_if(DB::table('booking_refunds')->where('booking_id',$booking)->whereIn('status',['requested','approved','processing','refunded'])->exists(),409);
            $paid=(int)$b->paid_irr;
            $rate=max(0,min(100,(int)BookingPolicy::get('cancellation_penalty_percent',config('booking.cancellation_penalty_percent',10))));
            $basis=BookingPolicy::get('cancellation_penalty_basis',config('booking.cancellation_penalty_basis','paid'))==='total'?(int)$b->total_irr:$paid;
            $penalty=min($paid,intdiv($basis*$rate,100));
            $id=DB::table('booking_refunds')->insertGetId(['booking_id'=>$booking,'requested_by'=>$r->user()->id,
                'base_amount_irr'=>$paid,'penalty_irr'=>$penalty,'refund_amount_irr'=>$paid-$penalty,
                'reason'=>$v['reason']??null,'status'=>'requested','created_at'=>now(),'updated_at'=>now()]);
            return response()->json(['data'=>['id'=>$id,'status'=>'requested','refund_amount_irr'=>$paid-$penalty,'penalty_irr'=>$penalty]],201);
        });
    }

    public function reschedule(Request $r,int $booking,BookingSlots $slots,BookingNotices $notices) {
        $v=$r->validate(['starts_at'=>'required|date_format:Y-m-d\\TH:i:sP']);
        $start=Carbon::parse($v['starts_at'])->utc();
        return DB::transaction(function()use($r,$booking,$slots,$notices,$start) {
            $current=DB::table('bookings')->where('id',$booking)->where('user_id',$r->user()->id)->first();
            abort_unless($current,404);
            DB::table('counselors')->where('id',$current->counselor_id)->lockForUpdate()->first();
            $b=DB::table('bookings')->where('id',$booking)->where('user_id',$r->user()->id)->lockForUpdate()->first();
            abort_unless(in_array($b->status,['held','confirmed'],true),409);
            abort_if(Carbon::parse($b->starts_at,'UTC')->lte(now()->utc()),409,'Appointment has started');
            abort_if($b->status==='held' && $b->hold_expires_at<=now()->utc()->toDateTimeString(),409,'Reservation expired');
            $cutoff=max(0,(int)BookingPolicy::get('reschedule_cutoff_hours',config('booking.reschedule_cutoff_hours',0)));
            abort_if(Carbon::parse($b->starts_at,'UTC')->lte(now()->utc()->addHours($cutoff)),422,'Rescheduling cutoff passed');
            $maxReschedules=max(0,(int)BookingPolicy::get('max_client_reschedules',config('booking.max_client_reschedules',2)));
            $reschedules=(int)DB::table('booking_audit_events')->where('booking_id',$booking)->where('action','booking.rescheduled_by_client')->count();
            abort_if($maxReschedules===0||$reschedules >= $maxReschedules,409,'Rescheduling limit reached');
            abort_if(DB::table('booking_refunds')->where('booking_id',$booking)->whereIn('status',['requested','approved','processing','refunded'])->exists(),409,'Cancellation under review');
            $service=$slots->service((int)$b->counselor_id,(int)$b->booking_service_id);
            $service->duration_minutes=(int)($b->duration_minutes ?: Carbon::parse($b->starts_at,'UTC')->diffInMinutes(Carbon::parse($b->ends_at,'UTC')));
            if($b->starts_at!==$start->toDateTimeString()) $slots->requireAvailable((int)$b->counselor_id,$service,$start);
            $notices->invalidateConnection($booking);
            DB::table('bookings')->where('id',$booking)->update(['starts_at'=>$start->toDateTimeString(),
                'ends_at'=>$start->copy()->addMinutes($service->duration_minutes)->toDateTimeString(),
                'connection_released_at'=>null,'connection_release_notified_at'=>null,'updated_at'=>now()]);
            DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'booking_id'=>$booking,'action'=>'booking.rescheduled_by_client',
                'details'=>json_encode(['starts_at'=>$start->toIso8601String()]),'created_at'=>now(),'updated_at'=>now()]);
            $notices->queue($booking,'rescheduled-'.$start->format('YmdHi'),'client');
            $notices->queue($booking,'rescheduled-'.$start->format('YmdHi'),'counselor');
            $notices->queue($booking,'rescheduled-'.$start->format('YmdHi'),'reception');
            return response()->json(['data'=>BookingResponse::presentForClient(DB::table('bookings')->find($booking))]);
        },3);
    }
}
