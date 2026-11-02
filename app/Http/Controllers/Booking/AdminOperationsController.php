<?php
namespace App\Http\Controllers\Booking;
use App\Http\Controllers\Controller;
use App\Services\BookingSlots;
use App\Services\BookingResponse;
use App\Services\BookingNotices;
use App\Services\BookingMoney;
use App\Services\BookingPricing;
use App\Services\BookingPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Jobs\SendBookingNotice;

class AdminOperationsController extends Controller {
    public function bookings(Request $r) {
        $v=$r->validate(['date'=>'nullable|date_format:Y-m-d','from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d',
            'counselor_id'=>'nullable|integer|exists:counselors,id','service_id'=>'nullable|integer|exists:booking_services,id',
            'room_id'=>'nullable|integer|exists:appointment_rooms,id','client_id'=>'nullable|integer|exists:users,id','status'=>'nullable|in:held,confirmed,checked_in,in_progress,completed,cancelled,expired,no_show',
            'q'=>'nullable|string|max:120']);
        $q=DB::table('bookings as b')->join('users as u','u.id','=','b.user_id')->join('counselors as c','c.id','=','b.counselor_id')
            ->join('booking_services as s','s.id','=','b.booking_service_id')->leftJoin('appointment_rooms as r','r.id','=','b.room_id')->select('b.*','u.name as client_name','u.phone as client_phone',
                'c.name as counselor_name','s.title as service_name','r.name as room_name')
            ->when($v['counselor_id']??null,fn($q,$id)=>$q->where('b.counselor_id',$id))
            ->when($v['service_id']??null,fn($q,$id)=>$q->where('b.booking_service_id',$id))
            ->when($v['room_id']??null,fn($q,$id)=>$q->where('b.room_id',$id))
            ->when($v['client_id']??null,fn($q,$id)=>$q->where('b.user_id',$id))
            ->when($v['status']??null,fn($q,$status)=>$q->where('status',$status));
        if(isset($v['q'])) $q->where(fn($w)=>$w->where('u.name','like','%'.$v['q'].'%')->orWhere('u.phone','like','%'.$v['q'].'%')
            ->orWhere('c.name','like','%'.$v['q'].'%')->orWhere('b.id',(int)$v['q']));
        if(isset($v['date']) || isset($v['from'])) {
            $from=$v['from']??$v['date']; $to=$v['to']??$from;
            $start=Carbon::createFromFormat('!Y-m-d',$from,BookingSlots::TIMEZONE);
            $end=Carbon::createFromFormat('!Y-m-d',$to,BookingSlots::TIMEZONE)->addDay();
            $q->where('b.starts_at','>=',$start->utc()->toDateTimeString())->where('b.starts_at','<',$end->utc()->toDateTimeString());
        }
        $page=$q->orderByDesc('b.starts_at')->paginate(30);
        $page->getCollection()->transform(fn($booking)=>BookingResponse::present($booking));
        return response()->json(['data'=>$page]);
    }
    public function show(int $booking) {
        $row=DB::table('bookings as b')->join('users as u','u.id','=','b.user_id')->join('counselors as c','c.id','=','b.counselor_id')
            ->join('booking_services as s','s.id','=','b.booking_service_id')->where('b.id',$booking)
            ->select('b.*','u.name as client_name','u.email as client_email','u.phone as client_phone','c.name as counselor_name','s.title as service_name')->first();
        abort_unless($row,404);
        return response()->json(['data'=>BookingResponse::present($row),'payments'=>DB::table('booking_payments')->where('booking_id',$booking)->orderBy('id')->get(),
            'receipts'=>DB::table('booking_receipts')->where('booking_id',$booking)->orderBy('id')->get(),
            'refunds'=>DB::table('booking_refunds')->where('booking_id',$booking)->orderBy('id')->get(),
            'history'=>DB::table('booking_audit_events')->where('booking_id',$booking)->orderBy('id')->get()]);
    }
    public function sessionStatus(Request $r,int $booking) {
        $v=$r->validate(['status'=>'required|in:in_progress,completed,no_show']);
        $row=DB::table('bookings')->where('id',$booking)->lockForUpdate()->first(); abort_unless($row,404);
        $allowed=match($v['status']) {'in_progress'=>['checked_in'],'completed'=>['confirmed','checked_in','in_progress'],'no_show'=>['confirmed'],default=>[]};
        abort_unless(in_array($row->status,$allowed,true),409);
        $timestamps=match($v['status']) {'in_progress'=>['session_started_at'=>now()],'completed'=>['session_completed_at'=>now()],default=>[]};
        DB::table('bookings')->where('id',$booking)->update(['status'=>$v['status'],...$timestamps,'updated_at'=>now()]);
        DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'booking_id'=>$booking,'action'=>'session.'.$v['status'],
            'details'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>BookingResponse::present(DB::table('bookings')->find($booking))]);
    }
    public function sessionDetails(Request $r,int $booking,BookingNotices $notices) {
        $v=$r->validate(['session_mode'=>'required|in:in_person,meet,voice_call','connection'=>'nullable|string|max:2000',
            'instructions'=>'nullable|string|max:4000']);
        if($v['session_mode']==='meet' && $v['connection']) abort_unless(filter_var($v['connection'],FILTER_VALIDATE_URL)
            && parse_url($v['connection'],PHP_URL_SCHEME)==='https'
            && in_array(parse_url($v['connection'],PHP_URL_HOST),['google.com','meet.google.com'],true),422,'Invalid Meet URL');
        if($v['session_mode']==='voice_call' && $v['connection']) abort_unless(preg_match('/^[+0-9()\-\s]{7,30}$/',$v['connection']),422,'Invalid phone number');
        $row=DB::table('bookings')->where('id',$booking)->first(); abort_unless($row,404);
        $notices->invalidateConnection($booking);
        DB::table('bookings')->where('id',$booking)->update(['session_mode'=>$v['session_mode'],'session_connection'=>$v['connection']??null,
            'session_instructions'=>$v['instructions']??null,'connection_released_at'=>null,'connection_release_notified_at'=>null,'updated_at'=>now()]);
        DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'booking_id'=>$booking,'action'=>'session.connection_updated',
            'details'=>json_encode(['mode'=>$v['session_mode'],'has_connection'=>!empty($v['connection'])]),'created_at'=>now(),'updated_at'=>now()]);
        if(empty($v['connection'])) $notices->queue($booking,'missing-connection-'.$row->starts_at,'reception','in_app');
        return response()->json(['data'=>['id'=>$booking,'session_mode'=>$v['session_mode'],'connection_set'=>!empty($v['connection'])]]);
    }
    public function create(Request $r,BookingSlots $slots,\App\Services\PromoCodeRedemption $promos) {
        $v=$r->validate(['user_id'=>'required|exists:users,id','counselor_id'=>'required|exists:counselors,id',
            'service_id'=>'required|exists:booking_services,id','room_id'=>'nullable|integer|exists:appointment_rooms,id','attendee_count'=>'sometimes|integer|min:1|max:100','starts_at'=>'required|date_format:Y-m-d\TH:i:sP',
            'mode'=>'required|in:online,in_person','session_mode'=>'nullable|in:in_person,meet,voice_call','payment_option'=>'required|in:full,deposit','promo_code'=>'nullable|string|max:64']);
        $start=Carbon::parse($v['starts_at'])->utc();
        $id=DB::transaction(function()use($r,$slots,$promos,$v,$start) {
            DB::table('counselors')->where('id',$v['counselor_id'])->lockForUpdate()->first();
            $service=$slots->service($v['counselor_id'],$v['service_id']);
            $slots->requireAvailable($v['counselor_id'],$service,$start);
            if ($v['mode'] !== 'in_person' && !empty($v['room_id'])) throw ValidationException::withMessages(['room_id'=>'Remote bookings cannot reserve an in-person room.']);
            if (!empty($v['room_id'])) { $this->requireRoomAvailable((int)$v['room_id'],$start,$start->copy()->addMinutes($service->duration_minutes));$roomCapacity=(int)DB::table('appointment_rooms')->where('id',$v['room_id'])->value('capacity');if(($v['attendee_count']??1)>$roomCapacity)throw ValidationException::withMessages(['attendee_count'=>'Attendee count exceeds room capacity.']); }
            $price=(int)$service->effective_price_irr;
            $promo=filled($v['promo_code']??null)?$promos->quote($v['promo_code'],(int)$v['user_id'],$price):null;
            if($promo) $price=$promo['price_irr'];
            $pricing=BookingPricing::snapshot((int)$service->id,$price,$service->commission_type,(string)$service->commission_value,$v['payment_option'],(int)$service->deposit_irr);
            $commission=$pricing['commission_total_irr']; $required=$pricing['required_irr'];
            if($required<=0) throw ValidationException::withMessages(['payment_option'=>'Positive payment required']);
            $id=DB::table('bookings')->insertGetId(['user_id'=>$v['user_id'],'created_by'=>$r->user()->id,
                'counselor_id'=>$v['counselor_id'],'room_id'=>$v['room_id']??null,'booking_service_id'=>$service->id,'mode'=>$v['mode'],
                'session_mode'=>$v['session_mode']??($v['mode']==='in_person'?'in_person':'meet'),'attendee_count'=>$v['attendee_count']??1,
                'starts_at'=>$start->toDateTimeString(),'ends_at'=>$start->copy()->addMinutes($service->duration_minutes)->toDateTimeString(),
                'status'=>'held','payment_option'=>$v['payment_option'],'total_irr'=>$pricing['total_irr'],'required_irr'=>$required,
                'commission_total_irr'=>$commission,'duration_minutes'=>$service->duration_minutes,
                'tax_irr'=>$pricing['tax_irr'],'tax_rate_basis_points'=>$pricing['tax_rate_basis_points'],
                'base_price_irr'=>$pricing['base_price_irr'],'center_share_irr'=>$pricing['center_share_irr'],
                'promo_code_id'=>$promo['promo_code_id']??null,'discount_irr'=>$promo['discount_irr']??0,
                'hold_expires_at'=>now()->utc()->addMinutes((int)BookingPolicy::get('hold_minutes',config('booking.hold_minutes',20)))->toDateTimeString(),
                'created_at'=>now(),'updated_at'=>now()]);
            if($promo) $promos->record($promo['promo_code_id'],$id,(int)$v['user_id'],$promo['discount_irr']);
            return $id;
        },3);
        return response()->json(['data'=>BookingResponse::present(DB::table('bookings')->find($id))],201);
    }
    public function reschedule(Request $r,int $booking,BookingSlots $slots,BookingNotices $notices) {
        $v=$r->validate(['starts_at'=>'required|date_format:Y-m-d\TH:i:sP']);
        $start=Carbon::parse($v['starts_at'])->utc();
        return DB::transaction(function()use($booking,$slots,$start,$notices,$r) {
            $b=DB::table('bookings')->where('id',$booking)->first(); abort_unless($b,404);
            DB::table('counselors')->where('id',$b->counselor_id)->lockForUpdate()->first();
            $b=DB::table('bookings')->where('id',$booking)->lockForUpdate()->first();
            abort_unless(in_array($b->status,['held','confirmed'],true),409);
            abort_if($b->status==='held' && $b->hold_expires_at<=now()->utc()->toDateTimeString(),409,'Reservation expired');
            abort_if(DB::table('booking_refunds')->where('booking_id',$booking)
                ->whereIn('status',['requested','approved','processing','refunded'])->exists(),409,'Cancellation under review');
            $service=$slots->service($b->counselor_id,$b->booking_service_id);
            $service->duration_minutes=(int)($b->duration_minutes ?:
                Carbon::parse($b->starts_at,'UTC')->diffInMinutes(Carbon::parse($b->ends_at,'UTC')));
            if($b->starts_at!==$start->toDateTimeString()) $slots->requireAvailable($b->counselor_id,$service,$start);
            if ($b->room_id) $this->requireRoomAvailable((int)$b->room_id,$start,$start->copy()->addMinutes($service->duration_minutes),$booking);
            $notices->invalidateConnection($booking);
            DB::table('bookings')->where('id',$booking)->update(['starts_at'=>$start->toDateTimeString(),
                'ends_at'=>$start->copy()->addMinutes($service->duration_minutes)->toDateTimeString(),
                'connection_released_at'=>null,'connection_release_notified_at'=>null,'updated_at'=>now()]);
            DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'booking_id'=>$booking,'action'=>'booking.rescheduled',
                'details'=>json_encode(['starts_at'=>$start->toIso8601String()]),'created_at'=>now(),'updated_at'=>now()]);
            $notices->queue($booking,'rescheduled-'.$start->format('YmdHi'),'client');
            return response()->json(['data'=>BookingResponse::present(DB::table('bookings')->find($booking))]);
        },3);
    }
    public function cancel(Request $r,int $booking,BookingNotices $notices) {
        $v=$r->validate(['reason'=>'required|string|max:2000']);
        return DB::transaction(function()use($booking,$v,$notices,$r) {
            $b=DB::table('bookings')->where('id',$booking)->lockForUpdate()->first(); abort_unless($b,404);
            abort_unless(in_array($b->status,['held','confirmed'],true),409);
            abort_if((int)$b->paid_irr>0,409,'Review refund before cancellation');
            $notices->invalidateConnection($booking);
            DB::table('bookings')->where('id',$booking)->update(['status'=>'cancelled','cancellation_reason'=>$v['reason'],'updated_at'=>now()]);
            DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'booking_id'=>$booking,'action'=>'booking.cancelled',
                'details'=>json_encode(['reason'=>$v['reason']]),'created_at'=>now(),'updated_at'=>now()]);
            $notices->queue($booking,'cancelled','client');
            return response()->json(['data'=>BookingResponse::present(DB::table('bookings')->find($booking))]);
        });
    }
    public function refunds(Request $r) {
        return response()->json(['data'=>DB::table('booking_refunds')->orderByDesc('id')->paginate(30)]);
    }
    public function partialRefundRequest(Request $r,int $booking) {
        $v=$r->validate(['amount_irr'=>'required|integer|min:1','reason'=>'required|string|max:2000']);
        $id=DB::transaction(function()use($r,$booking,$v) {
            $b=DB::table('bookings')->where('id',$booking)->lockForUpdate()->first(); abort_unless($b,404);
            abort_if((int)$b->paid_irr<1,409,'There is no paid balance to refund.');
            $pending=(int)DB::table('booking_refunds')->where('booking_id',$booking)->whereIn('status',['requested','approved','processing'])->sum('refund_amount_irr');
            $completed=(int)DB::table('booking_refunds')->where('booking_id',$booking)->where('status','refunded')->sum('refund_amount_irr');
            $available=max(0,(int)$b->paid_irr-$pending-$completed);
            if($v['amount_irr']>$available) throw ValidationException::withMessages(['amount_irr'=>'Amount exceeds the unrefunded paid balance.']);
            return DB::table('booking_refunds')->insertGetId(['booking_id'=>$booking,'requested_by'=>$r->user()->id,
                'base_amount_irr'=>$v['amount_irr'],'penalty_irr'=>0,'refund_amount_irr'=>$v['amount_irr'],'reason'=>$v['reason'],
                'status'=>'requested','cancels_booking'=>false,'created_at'=>now(),'updated_at'=>now()]);
        },3);
        return response()->json(['data'=>DB::table('booking_refunds')->find($id)],201);
    }
    public function refundDecision(Request $r,int $refund,BookingNotices $notices) {
        $v=$r->validate(['decision'=>'required|in:approved,rejected']);
        return DB::transaction(function()use($r,$refund,$v,$notices) {
            $row=DB::table('booking_refunds')->where('id',$refund)->lockForUpdate()->first(); abort_unless($row,404);
            abort_unless($row->status==='requested',409);
            DB::table('booking_refunds')->where('id',$refund)->update(['status'=>$v['decision'],
                'approved_by'=>$r->user()->id,'updated_at'=>now()]);
            if($v['decision']==='approved' && $row->cancels_booking) $notices->invalidateConnection((int)$row->booking_id);
            if($v['decision']==='approved' && $row->cancels_booking) DB::table('bookings')->where('id',$row->booking_id)
                ->update(['status'=>'cancelled','updated_at'=>now()]);
            if($v['decision']==='approved' && $row->cancels_booking) $notices->queue($row->booking_id,'cancelled','client');
            if($v['decision']==='approved' && !$row->cancels_booking) $notices->queue($row->booking_id,'partial-refund-approved-'.$refund,'client');
            return response()->json(['data'=>DB::table('booking_refunds')->find($refund)]);
        });
    }
    public function reports(Request $r) {
        $v=$r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d',
            'counselor_id'=>'nullable|exists:counselors,id','provider'=>'nullable|in:fake_bank,mock,zarinpal,snappay,in_person',
            'payment_method'=>'nullable|in:cash,pos',
            'booking_status'=>'nullable|in:held,confirmed,checked_in,in_progress,completed,cancelled,expired,no_show','service_id'=>'nullable|exists:booking_services,id',
            'group'=>'nullable|in:day,week,month']);
        if(isset($v['from'],$v['to']) && $v['from']>$v['to']) throw ValidationException::withMessages(['to'=>'End date must be on or after start date']);
        $q=DB::table('bookings as b')
            ->when($v['counselor_id']??null,fn($q,$id)=>$q->where('b.counselor_id',$id))
            ->when($v['service_id']??null,fn($q,$id)=>$q->where('b.booking_service_id',$id))
            ->when($v['booking_status']??null,fn($q,$status)=>$q->where('b.status',$status))
            ->when(isset($v['provider']) || isset($v['payment_method']),fn($q)=>$q->whereExists(fn($p)=>$p->selectRaw('1')
                ->from('booking_payments as p')->whereColumn('p.booking_id','b.id')->where('p.status','verified')
                ->when($v['provider']??null,fn($p,$provider)=>$p->where('p.provider',$provider))
                ->when($v['payment_method']??null,fn($p,$method)=>$p->where('p.payment_method',$method))));
        if(isset($v['from'])) $q->where('b.starts_at','>=',Carbon::parse($v['from'],BookingSlots::TIMEZONE)->startOfDay()->utc()->toDateTimeString());
        if(isset($v['to'])) $q->where('b.starts_at','<',Carbon::parse($v['to'],BookingSlots::TIMEZONE)->addDay()->startOfDay()->utc()->toDateTimeString());
        $count=(clone $q)->count();
        $statusCounts=(clone $q)->select('b.status')->selectRaw('COUNT(*) AS count')->groupBy('b.status')->pluck('count','status');
        $repeatClients=DB::query()->fromSub((clone $q)->select('b.user_id')->groupBy('b.user_id')->havingRaw('COUNT(*)>1'),'repeat_clients')->count();
        $utilizedMinutes=(int)(clone $q)->whereIn('b.status',['confirmed','checked_in','in_progress','completed'])->sum(DB::raw('COALESCE(b.duration_minutes,0)'));
        $capacityMinutes=null; $capacityUtilization=null;
        if(isset($v['from'],$v['to'])) {
            $first=Carbon::createFromFormat('!Y-m-d',$v['from'],BookingSlots::TIMEZONE);
            $last=Carbon::createFromFormat('!Y-m-d',$v['to'],BookingSlots::TIMEZONE);
            if($first->diffInDays($last)>366) throw ValidationException::withMessages(['to'=>'Capacity report range cannot exceed 366 days']);
            $weekly=DB::table('counselor_working_hours')->where('is_active',true)
                ->when($v['counselor_id']??null,fn($hours,$id)=>$hours->where('counselor_id',$id))
                ->get(['weekday','starts_at','ends_at'])->groupBy('weekday')->map(fn($shifts)=>$shifts->sum(
                    fn($shift)=>Carbon::parse($shift->starts_at)->diffInMinutes(Carbon::parse($shift->ends_at))));
            $capacityMinutes=0;
            for($date=$first->copy();$date->lte($last);$date->addDay()) $capacityMinutes+=(int)($weekly[$date->dayOfWeek]??0);
            $capacityUtilization=$capacityMinutes>0?round($utilizedMinutes*100/$capacityMinutes,2):0;
        }
        $outstanding=(int)(clone $q)->whereIn('b.status',['confirmed','checked_in','in_progress','completed'])
            ->selectRaw('SUM(b.total_irr-b.paid_irr) AS balance')->value('balance');
        $payments=DB::table('booking_payments as p')->joinSub((clone $q)->select('b.id'),'selected','selected.id','=','p.booking_id')
            ->where('p.status','verified')->when($v['provider']??null,fn($p,$provider)=>$p->where('p.provider',$provider))
            ->when($v['payment_method']??null,fn($p,$method)=>$p->where('p.payment_method',$method));
        $collected=(int)(clone $payments)->sum('p.amount_irr');
        $refunds=(int)DB::table('booking_refunds as f')->joinSub((clone $q)->select('b.id'),'selected','selected.id','=','f.booking_id')
            ->where('f.status','refunded')->sum('f.refund_amount_irr');
        $ledger=DB::table('booking_ledger_entries as l')->joinSub((clone $q)->select('b.id'),'selected','selected.id','=','l.booking_id');
        $commissionCredit=(int)(clone $ledger)->where('l.account','counselor_payable')->where('l.direction','credit')->sum('l.amount_irr');
        $commissionDebit=(int)(clone $ledger)->where('l.account','counselor_payable')->where('l.direction','debit')->sum('l.amount_irr');
        $revenueCredit=(int)(clone $ledger)->where('l.account','center_revenue')->where('l.direction','credit')->sum('l.amount_irr');
        $revenueDebit=(int)(clone $ledger)->where('l.account','center_revenue')->where('l.direction','debit')->sum('l.amount_irr');
        $groups=[];
        if(isset($v['group'])) (clone $payments)->select('p.id','p.amount_irr','p.verified_at')->orderBy('p.id')
            ->chunkById(500,function($rows)use(&$groups,$v) {
                foreach($rows as $payment) {
                    $date=Carbon::parse($payment->verified_at,'UTC')->setTimezone(BookingSlots::TIMEZONE);
                    $key=match($v['group']) {'day'=>$date->format('Y-m-d'),'week'=>$date->format('o-\WW'),'month'=>$date->format('Y-m')};
                    $groups[$key]=($groups[$key]??0)+(int)$payment->amount_irr;
                }
            },'p.id','id');
        ksort($groups);
        return response()->json(['data'=>['bookings'=>$count,'status_counts'=>$statusCounts,'cancellation_rate'=>$count?round((int)($statusCounts['cancelled']??0)*100/$count,2):0,
            'no_show_rate'=>$count?round((int)($statusCounts['no_show']??0)*100/$count,2):0,'repeat_clients'=>$repeatClients,
            'utilized_minutes'=>$utilizedMinutes,'collected_irr'=>$collected,'refunded_irr'=>$refunds,
            'scheduled_capacity_minutes'=>$capacityMinutes,'capacity_utilization_percent'=>$capacityUtilization,
            'net_collected_irr'=>$collected-$refunds,'outstanding_irr'=>$outstanding,
            'counselor_payable_irr'=>$commissionCredit-$commissionDebit,
            'center_revenue_irr'=>$revenueCredit-$revenueDebit,'periods'=>$groups]]);
    }
    public function exportReports(Request $r) {
        $v=$r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d',
            'counselor_id'=>'nullable|exists:counselors,id','service_id'=>'nullable|exists:booking_services,id',
            'booking_status'=>'nullable|in:held,confirmed,checked_in,in_progress,completed,cancelled,expired,no_show']);
        $query=DB::table('bookings as b')->join('users as u','u.id','=','b.user_id')->join('counselors as c','c.id','=','b.counselor_id')
            ->join('booking_services as s','s.id','=','b.booking_service_id')
            ->when($v['counselor_id']??null,fn($q,$id)=>$q->where('b.counselor_id',$id))
            ->when($v['service_id']??null,fn($q,$id)=>$q->where('b.booking_service_id',$id))
            ->when($v['booking_status']??null,fn($q,$status)=>$q->where('b.status',$status))
            ->select('b.id','b.starts_at','b.status','b.mode','b.session_mode','b.total_irr','b.discount_irr','b.tax_irr','b.paid_irr','b.duration_minutes',
                'u.name as client_name','c.name as counselor_name','s.title as service_name');
        if(isset($v['from'])) $query->where('b.starts_at','>=',Carbon::parse($v['from'],BookingSlots::TIMEZONE)->startOfDay()->utc());
        if(isset($v['to'])) $query->where('b.starts_at','<',Carbon::parse($v['to'],BookingSlots::TIMEZONE)->addDay()->startOfDay()->utc());
        return response()->streamDownload(function()use($query) {
            $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
            fputcsv($out,['رزرو','تاریخ UTC','مراجع','مشاور','خدمت','وضعیت','نوع جلسه','مبلغ (ریال)','تخفیف (ریال)','مالیات (ریال)','پرداخت‌شده (ریال)','مدت (دقیقه)']);
            $query->orderBy('b.id')->chunk(500,function($rows)use($out) {
                foreach($rows as $row) fputcsv($out,[$row->id,$row->starts_at,$row->client_name,$row->counselor_name,$row->service_name,
                    $row->status,$row->session_mode,(int)$row->total_irr,(int)$row->discount_irr,(int)$row->tax_irr,(int)$row->paid_irr,(int)$row->duration_minutes]);
            }); fclose($out);
        },'booking-report.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
    }

    public function operationalReport(Request $r) {
        abort_unless($r->user()->hasAdminPermission('appointments.manage') || $r->user()->hasAdminPermission('finance.view'), 403);
        $v=$r->validate(['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from',
            'counselor_id'=>'nullable|exists:counselors,id','service_id'=>'nullable|exists:booking_services,id']);
        $first=Carbon::createFromFormat('!Y-m-d',$v['from'],BookingSlots::TIMEZONE)->startOfDay();
        $last=Carbon::createFromFormat('!Y-m-d',$v['to'],BookingSlots::TIMEZONE)->startOfDay();
        abort_if($first->diffInDays($last)>366,422,'Report range cannot exceed 366 days.');
        $fromUtc=$first->copy()->utc()->toDateTimeString(); $untilUtc=$last->copy()->addDay()->utc()->toDateTimeString();
        $counselors=DB::table('counselors')->where('is_active',true)->whereNull('deleted_at')
            ->whereExists(fn($q)=>$q->selectRaw('1')->from('counselor_booking_services as cbs')->join('booking_services as bs','bs.id','=','cbs.booking_service_id')->whereColumn('cbs.counselor_id','counselors.id')->where('bs.is_active',true))
            ->when($v['counselor_id']??null,fn($q,$id)=>$q->where('id',$id))->orderBy('name')->get(['id','name']);
        $hours=DB::table('counselor_working_hours')->where('is_active',true)->whereIn('counselor_id',$counselors->pluck('id'))
            ->get(['counselor_id','weekday','starts_at','ends_at'])->groupBy('counselor_id');
        $timeOff=DB::table('counselor_time_off')->whereIn('counselor_id',$counselors->pluck('id'))->where('starts_at','<',$untilUtc)->where('ends_at','>',$fromUtc)->get()->groupBy('counselor_id');
        $roomRows=DB::table('appointment_rooms')->where('is_active',true)->get(['id']);$activeRooms=$roomRows->count();
        $roomClosures=DB::table('appointment_room_unavailability')->whereIn('room_id',$roomRows->pluck('id'))->where('starts_at','<',$untilUtc)->where('ends_at','>',$fromUtc)->get();
        $closureMap=$roomClosures->groupBy('room_id')->map(fn($rows)=>$rows->map(fn($w)=>[Carbon::parse($w->starts_at,'UTC'),Carbon::parse($w->ends_at,'UTC')]));
        $roomSchedule=DB::table('appointment_room_working_hours')->whereIn('room_id',$roomRows->pluck('id'))->get()->groupBy('room_id');
        $holidays=DB::table('center_holidays')->whereBetween('holiday_date',[$first->toDateString(),$last->toDateString()])->get()->keyBy('holiday_date');
        $centerHours=DB::table('center_operating_hours')->get()->keyBy('weekday');
        $capacityByCounselor=[]; $roomConstrainedMinutes=0;
        for($date=$first->copy();$date->lte($last);$date->addDay()) {
            $holiday=$holidays->get($date->toDateString());$centerDay=$holiday??$centerHours->get($date->dayOfWeek);if($centerDay && !$centerDay->is_open)continue;
            $day=$date->dayOfWeek; $slotAvailability=[];
            foreach($counselors as $counselor) {
                $shiftIntervals=[];
                foreach($hours->get($counselor->id,collect())->where('weekday',$day) as $shift) {
                    $shiftStart=$date->copy()->setTimeFromTimeString($shift->starts_at);$shiftEnd=$date->copy()->setTimeFromTimeString($shift->ends_at);
                    if($centerDay){$open=$date->copy()->setTimeFromTimeString($centerDay->opens_at);$close=$date->copy()->setTimeFromTimeString($centerDay->closes_at);if($open->gt($shiftStart))$shiftStart=$open;if($close->lt($shiftEnd))$shiftEnd=$close;}
                    if($shiftStart->lt($shiftEnd))$shiftIntervals[]=[$shiftStart,$shiftEnd];
                }
                usort($shiftIntervals,fn($a,$b)=>$a[0]<=>$b[0]);$mergedShifts=[];
                foreach($shiftIntervals as [$a,$b]){$lastIndex=count($mergedShifts)-1;if($lastIndex>=0&&$a->lte($mergedShifts[$lastIndex][1])){if($b->gt($mergedShifts[$lastIndex][1]))$mergedShifts[$lastIndex][1]=$b;}else $mergedShifts[]=[$a,$b];}
                foreach($mergedShifts as [$start,$end]) {
                    $offIntervals=[];
                    foreach($timeOff->get($counselor->id,collect()) as $off) {
                        $offStart=Carbon::parse($off->starts_at,'UTC')->setTimezone(BookingSlots::TIMEZONE);
                        $offEnd=Carbon::parse($off->ends_at,'UTC')->setTimezone(BookingSlots::TIMEZONE);
                        $a=$offStart->greaterThan($start)?$offStart:$start; $b=$offEnd->lessThan($end)?$offEnd:$end;
                        if($a->lt($b)) $offIntervals[]=[$a,$b];
                    }
                    usort($offIntervals,fn($a,$b)=>$a[0]<=>$b[0]); $merged=[];
                    foreach($offIntervals as [$a,$b]) { $lastIndex=count($merged)-1; if($lastIndex>=0 && $a->lte($merged[$lastIndex][1])) { if($b->gt($merged[$lastIndex][1]))$merged[$lastIndex][1]=$b; } else $merged[]=[$a,$b]; }
                    $net=$start->diffInMinutes($end)-array_sum(array_map(fn($i)=>$i[0]->diffInMinutes($i[1]),$merged));
                    $capacityByCounselor[$counselor->id]=($capacityByCounselor[$counselor->id]??0)+max(0,$net);
                    for($cursor=$start->copy();$cursor->lt($end);$cursor->addMinutes(15)) {
                        $slotEnd=$cursor->copy()->addMinutes(15); if($slotEnd->gt($end))$slotEnd=$end;
                        $isOff=false; foreach($merged as [$a,$b]) if($cursor->lt($b)&&$slotEnd->gt($a)){$isOff=true;break;}
                        if(!$isOff) { $key=$cursor->timestamp; $slotAvailability[$key]=($slotAvailability[$key]??0)+$cursor->diffInMinutes($slotEnd)/15; }
                    }
                }
            }
            foreach($slotAvailability as $timestamp=>$concurrent) {
                $slotStart=Carbon::createFromTimestamp((int)$timestamp,'UTC');$slotEnd=$slotStart->copy()->addMinutes(15);$local=$slotStart->copy()->setTimezone(BookingSlots::TIMEZONE);$availableRooms=0;
                foreach($roomRows as $room){$hours=$roomSchedule->get($room->id,collect());if($hours->isNotEmpty()&&!$hours->contains(fn($h)=>(int)$h->weekday===$local->dayOfWeek&&$h->opens_at<=$local->format('H:i:s')&&$h->closes_at>=$slotEnd->copy()->setTimezone(BookingSlots::TIMEZONE)->format('H:i:s')))continue;
                    $closed=($closureMap->get($room->id,collect()))->contains(fn($i)=>$i[0]->lt($slotEnd)&&$i[1]->gt($slotStart));if(!$closed)$availableRooms++;}
                $roomConstrainedMinutes+=(int)(min($concurrent,$availableRooms)*15);
            }
        }
        $bookings=DB::table('bookings as b')->where('b.starts_at','>=',$fromUtc)->where('b.starts_at','<',$untilUtc)
            ->when($v['counselor_id']??null,fn($q,$id)=>$q->where('b.counselor_id',$id))->when($v['service_id']??null,fn($q,$id)=>$q->where('b.booking_service_id',$id));
        $statusCounts=(clone $bookings)->select('b.status')->selectRaw('COUNT(*) as total')->groupBy('b.status')->pluck('total','status');
        $utilized=(int)(clone $bookings)->whereIn('b.status',['confirmed','checked_in','in_progress','completed'])->sum('b.duration_minutes');
        $inPersonUtilized=(int)(clone $bookings)->where('b.mode','in_person')->whereIn('b.status',['confirmed','checked_in','in_progress','completed'])->sum('b.duration_minutes');
        $counselorRows=$counselors->map(function($c)use($bookings,$capacityByCounselor){$count=(clone $bookings)->where('b.counselor_id',$c->id)->whereIn('b.status',['confirmed','checked_in','in_progress','completed'])->count();$minutes=(int)($capacityByCounselor[$c->id]??0);$used=(int)(clone $bookings)->where('b.counselor_id',$c->id)->whereIn('b.status',['confirmed','checked_in','in_progress','completed'])->sum('b.duration_minutes');return ['counselor_id'=>$c->id,'counselor_name'=>$c->name,'appointments'=>$count,'scheduled_minutes'=>$minutes,'utilized_minutes'=>$used,'utilization_percent'=>$minutes?round($used*100/$minutes,2):0];});
        $receptionIds=DB::table('users')->where('is_admin',true)->pluck('id')->all();
        foreach(DB::table('users')->whereNotNull('admin_permissions')->get(['id','admin_permissions']) as $staff) if(in_array('appointments.manage',json_decode($staff->admin_permissions?:'[]',true)?:[],true))$receptionIds[]=$staff->id;
        $receptionIds=array_merge($receptionIds,DB::table('admin_user_roles as ur')->join('admin_role_permissions as rp','rp.role_id','=','ur.role_id')->where('rp.permission','appointments.manage')->pluck('ur.user_id')->all());
        $reception=DB::table('bookings as b')->leftJoin('users as u','u.id','=','b.created_by')->whereIn('b.created_by',array_unique($receptionIds))
            ->where('b.starts_at','>=',$fromUtc)->where('b.starts_at','<',$untilUtc)->when($v['service_id']??null,fn($q,$id)=>$q->where('b.booking_service_id',$id))
            ->select('b.created_by','u.name as staff_name')->selectRaw('COUNT(*) as created_count')
            ->selectRaw("SUM(CASE WHEN b.status='completed' THEN 1 ELSE 0 END) as completed_count")
            ->selectRaw("SUM(CASE WHEN b.status='cancelled' THEN 1 ELSE 0 END) as cancelled_count")
            ->groupBy('b.created_by','u.name')->orderByDesc('created_count')->get();
        return response()->json(['timezone'=>BookingSlots::TIMEZONE,'data'=>['range'=>['from'=>$first->toDateString(),'to'=>$last->toDateString()],
            'appointment_status_counts'=>$statusCounts,'cancellations'=>(int)($statusCounts['cancelled']??0),'no_shows'=>(int)($statusCounts['no_show']??0),
            'counselor_capacity_minutes'=>array_sum($capacityByCounselor),'room_constrained_capacity_minutes'=>$roomConstrainedMinutes,
            'utilized_minutes'=>$utilized,'in_person_utilized_minutes'=>$inPersonUtilized,'utilization_percent'=>$roomConstrainedMinutes?round($inPersonUtilized*100/$roomConstrainedMinutes,2):0,
            'active_rooms'=>$activeRooms,'room_capacity_assumption'=>'Capacity is estimated in 15-minute slots as the minimum of counselors with schedulable availability and active rooms open under weekly room hours and center/holiday hours, excluding recorded room maintenance. It is an aggregate concurrency estimate; it does not optimize variable appointment durations or infer room suitability beyond configured capacity.',
            'counselors'=>$counselorRows,'reception_performance'=>$reception]]);
    }
    public function payments(Request $r) { return response()->json(['data'=>DB::table('booking_payments')
        ->select('id','booking_id','provider','status','amount_irr','payment_method','provider_reference','verified_at','recorded_by','created_at')
        ->orderByDesc('id')->paginate(30)]); }
    public function exportPayments(Request $r) {
        $v=$r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d','counselor_id'=>'nullable|exists:counselors,id',
            'service_id'=>'nullable|exists:booking_services,id','status'=>'nullable|in:created,pending,verified,failed,refunded']);
        $query=DB::table('booking_payments as p')->join('bookings as b','b.id','=','p.booking_id')
            ->join('booking_services as s','s.id','=','b.booking_service_id')->join('counselors as c','c.id','=','b.counselor_id')
            ->join('users as u','u.id','=','b.user_id')->leftJoin('booking_receipts as r','r.payment_id','=','p.id')
            ->when($v['counselor_id']??null,fn($q,$id)=>$q->where('b.counselor_id',$id))
            ->when($v['service_id']??null,fn($q,$id)=>$q->where('b.booking_service_id',$id))
            ->when($v['status']??null,fn($q,$status)=>$q->where('p.status',$status))
            ->when($v['from']??null,fn($q,$date)=>$q->where('p.created_at','>=',Carbon::parse($date,BookingSlots::TIMEZONE)->utc()))
            ->when($v['to']??null,fn($q,$date)=>$q->where('p.created_at','<',Carbon::parse($date,BookingSlots::TIMEZONE)->addDay()->utc()))
            ->select('p.id','p.booking_id','p.provider','p.status','p.amount_irr','p.provider_reference','p.verified_at',
                'r.receipt_number','u.name as client_name','c.name as counselor_name','s.title as service_name');
        return response()->streamDownload(function()use($query) {
            $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
            fputcsv($out,['شناسه','رزرو','درگاه','وضعیت','مبلغ (ریال)','شناسه تراکنش','زمان پرداخت','رسید','مراجع','مشاور','خدمت']);
            $query->orderBy('p.id')->chunk(500,function($rows)use($out) { foreach($rows as $row) fputcsv($out,(array)$row); });
            fclose($out);
        },'booking-payments.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
    }
    public function notifications(Request $r) {
        $v=$r->validate(['status'=>'nullable|string|max:30','channel'=>'nullable|in:in_app,sms,email','event'=>'nullable|string|max:80']);
        return response()->json(['data'=>DB::table('booking_notification_logs')->when($v['status']??null,fn($q,$s)=>$q->where('status',$s))
            ->when($v['channel']??null,fn($q,$c)=>$q->where('channel',$c))->when($v['event']??null,fn($q,$e)=>$q->where('event',$e))
            ->orderByDesc('id')->paginate(30)]);
    }
    public function notificationTemplates(Request $r) {
        if($r->isMethod('get')) return response()->json(['data'=>DB::table('booking_notification_templates')->orderBy('event')->paginate(50)]);
        $v=$r->validate(['event'=>'required|string|max:80|regex:/^[a-zA-Z0-9._-]+$/','channel'=>'required|in:email,sms',
            'subject'=>'nullable|string|max:180','body'=>'required|string|max:10000','is_active'=>'sometimes|boolean']);
        if($v['channel']==='sms' && config('booking.sms_driver')!=='log') abort(409,'Real SMS delivery is disabled.');
        DB::table('booking_notification_templates')->updateOrInsert(['event'=>$v['event'],'channel'=>$v['channel']],
            [...$v,'is_active'=>$v['is_active']??true,'updated_at'=>now(),'created_at'=>now()]);
        DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'action'=>'notification.template_updated',
            'details'=>json_encode(['event'=>$v['event'],'channel'=>$v['channel']]),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('booking_notification_templates')->where('event',$v['event'])->where('channel',$v['channel'])->first()],201);
    }
    public function retryNotification(Request $r,int $notice) {
        $row=DB::table('booking_notification_logs')->where('id',$notice)->first(); abort_unless($row,404);
        abort_unless(in_array($row->channel,['email','sms'],true) && $row->status==='failed',409,'Only failed email/SMS delivery can be retried.');
        DB::table('booking_notification_logs')->where('id',$notice)->where('status','failed')->update(['status'=>'pending','error'=>null,'updated_at'=>now()]);
        SendBookingNotice::dispatch($notice)->afterCommit();
        return response()->json(['data'=>DB::table('booking_notification_logs')->find($notice)],202);
    }
    public function notes(int $booking) {
        abort_unless(DB::table('bookings')->where('id',$booking)->exists(),404);
        return response()->json(['data'=>DB::table('booking_notes')->where('booking_id',$booking)->orderByDesc('id')->get()]);
    }
    public function noteStore(Request $r,int $booking) {
        abort_unless(DB::table('bookings')->where('id',$booking)->exists(),404);
        $v=$r->validate(['body'=>'required|string|max:20000','visible_to_client'=>'required|boolean']);
        $id=DB::table('booking_notes')->insertGetId([...$v,'booking_id'=>$booking,'author_id'=>$r->user()->id,
            'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('booking_notes')->find($id)],201);
    }

    private function requireRoomAvailable(int $room, Carbon $start, Carbon $end, ?int $ignoreBooking = null): void
    {
        $roomRow=DB::table('appointment_rooms')->where('id',$room)->lockForUpdate()->first();
        abort_unless($roomRow && $roomRow->is_active,422,'Room is not available.');
        if(DB::table('appointment_room_unavailability')->where('room_id',$room)->where('starts_at','<',$end)->where('ends_at','>',$start)->exists())throw ValidationException::withMessages(['room_id'=>'Room is under maintenance during this period.']);
        $localStart=$start->copy()->setTimezone(BookingSlots::TIMEZONE);$localEnd=$end->copy()->subSecond()->setTimezone(BookingSlots::TIMEZONE);
        foreach(DB::table('center_holidays')->whereBetween('holiday_date',[$localStart->toDateString(),$localEnd->toDateString()])->get() as $holiday){if(!$holiday->is_open)throw ValidationException::withMessages(['room_id'=>'Center is closed on this date.']);$opens=Carbon::parse($holiday->holiday_date.' '.$holiday->opens_at,BookingSlots::TIMEZONE);$closes=Carbon::parse($holiday->holiday_date.' '.$holiday->closes_at,BookingSlots::TIMEZONE);if(($localStart->toDateString()===$holiday->holiday_date&&$localStart->lt($opens))||($localEnd->toDateString()===$holiday->holiday_date&&$localEnd->gte($closes)))throw ValidationException::withMessages(['room_id'=>'Appointment is outside holiday opening hours.']);}
        $conflict=DB::table('bookings')->where('room_id',$room)->when($ignoreBooking,fn($q,$id)=>$q->where('id','<>',$id))
            ->where('starts_at','<',$end->toDateTimeString())->where('ends_at','>',$start->toDateTimeString())
            ->where(fn($q)=>$q->whereIn('status',['confirmed','checked_in','in_progress'])
                ->orWhere(fn($q)=>$q->where('status','held')->where('hold_expires_at','>',now()->utc()->toDateTimeString())))->exists();
        if($conflict) throw ValidationException::withMessages(['room_id'=>'Room is already booked for this time.']);
    }
}
