<?php
namespace App\Http\Controllers\Booking;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceController extends Controller {
    public function promoCodes(Request $r) {
        $page=DB::table('booking_promo_codes')->orderByDesc('id')->paginate(30);
        $page->getCollection()->transform(function($code) {
            $code->redemptions=(int)DB::table('booking_promo_redemptions')->where('promo_code_id',$code->id)->count();
            return $code;
        });
        return response()->json(['data'=>$page]);
    }
    public function promoCodeStore(Request $r) {
        if($r->filled('code')) $r->merge(['code'=>strtoupper(trim((string)$r->input('code')))]);
        $v=$r->validate(['code'=>'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/|unique:booking_promo_codes,code',
            'label'=>'nullable|string|max:180','discount_type'=>'required|in:percentage,fixed','discount_value'=>'required|integer|min:1|max:1000000000',
            'max_redemptions'=>'nullable|integer|min:1','max_per_client'=>'sometimes|integer|min:1|max:100',
            'starts_at'=>'nullable|date','ends_at'=>'nullable|date|after:starts_at','is_active'=>'sometimes|boolean']);
        if($v['discount_type']==='percentage' && $v['discount_value']>9999) throw ValidationException::withMessages(['discount_value'=>'Percentage is expressed in basis points and must be below 100%.']);
        $id=DB::table('booking_promo_codes')->insertGetId([...$v,'max_per_client'=>$v['max_per_client']??1,
            'is_active'=>$v['is_active']??true,'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'action'=>'finance.promo_code_created',
            'details'=>json_encode(['promo_code_id'=>$id,'code'=>$v['code']]),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('booking_promo_codes')->find($id)],201);
    }
    public function promoCodeUpdate(Request $r,int $promo) {
        $v=$r->validate(['label'=>'sometimes|nullable|string|max:180','discount_value'=>'sometimes|required|integer|min:1|max:1000000000',
            'max_redemptions'=>'sometimes|nullable|integer|min:1','max_per_client'=>'sometimes|integer|min:1|max:100',
            'starts_at'=>'sometimes|nullable|date','ends_at'=>'sometimes|nullable|date','is_active'=>'sometimes|boolean']);
        $row=DB::table('booking_promo_codes')->find($promo); abort_unless($row,404);
        if($row->discount_type==='percentage' && ($v['discount_value']??$row->discount_value)>9999) throw ValidationException::withMessages(['discount_value'=>'Percentage is expressed in basis points and must be below 100%.']);
        DB::table('booking_promo_codes')->where('id',$promo)->update([...$v,'updated_at'=>now()]);
        DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'action'=>'finance.promo_code_updated',
            'details'=>json_encode(['promo_code_id'=>$promo,'fields'=>array_keys($v)]),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('booking_promo_codes')->find($promo)]);
    }
    /** Aggregated finance overview; all filters are applied in SQL. */
    public function dashboard(Request $r) {
        $v=$r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d',
            'counselor_id'=>'nullable|exists:counselors,id','service_id'=>'nullable|exists:booking_services,id']);
        if(isset($v['from']) && isset($v['to']) && $v['from']>$v['to'])
            throw ValidationException::withMessages(['to'=>'End date must be on or after start date']);
        $bookings=DB::table('bookings as b')
            ->when($v['counselor_id']??null,fn($q,$id)=>$q->where('b.counselor_id',$id))
            ->when($v['service_id']??null,fn($q,$id)=>$q->where('b.booking_service_id',$id))
            ->when($v['from']??null,fn($q,$date)=>$q->where('b.starts_at','>=',\Illuminate\Support\Carbon::parse($date)->startOfDay()->utc()))
            ->when($v['to']??null,fn($q,$date)=>$q->where('b.starts_at','<',\Illuminate\Support\Carbon::parse($date)->addDay()->startOfDay()->utc()));
        $selected=(clone $bookings)->select('b.id');
        $payments=DB::table('booking_payments as p')->joinSub($selected,'selected','selected.id','=','p.booking_id')->where('p.status','verified');
        $ledger=DB::table('booking_ledger_entries as l')->joinSub($selected,'selected','selected.id','=','l.booking_id');
        $refunds=(int)DB::table('booking_refunds as f')->joinSub($selected,'selected','selected.id','=','f.booking_id')
            ->where('f.status','refunded')->sum('f.refund_amount_irr');
        $balance=fn(string $account)=>(int)(clone $ledger)->where('l.account',$account)
            ->selectRaw("SUM(CASE WHEN l.direction='credit' THEN l.amount_irr ELSE -l.amount_irr END) AS balance")->value('balance');
        $byService=(clone $payments)->join('bookings as b','b.id','=','p.booking_id')->join('booking_services as s','s.id','=','b.booking_service_id')
            ->select('s.id','s.title')->selectRaw('SUM(p.amount_irr) AS revenue_irr')
            ->groupBy('s.id','s.title')->orderBy('s.title')->get();
        $byCounselor=(clone $payments)->join('bookings as b','b.id','=','p.booking_id')->join('counselors as c','c.id','=','b.counselor_id')
            ->select('c.id','c.name')->selectRaw('SUM(p.amount_irr) AS revenue_irr')->groupBy('c.id','c.name')->orderBy('c.name')->get();
        $byDate=(clone $payments)->selectRaw('DATE(p.verified_at) AS date, SUM(p.amount_irr) AS revenue_irr')
            ->groupByRaw('DATE(p.verified_at)')->orderBy('date')->get();
        $counselorPayable=$balance('counselor_payable');
        $settlements=DB::table('booking_settlements')->when($v['counselor_id']??null,fn($q,$id)=>$q->where('counselor_id',$id))
            ->when($v['from']??null,fn($q,$date)=>$q->where('created_at','>=',$date.' 00:00:00'))
            ->when($v['to']??null,fn($q,$date)=>$q->where('created_at','<',\Illuminate\Support\Carbon::parse($date)->addDay()->toDateString().' 00:00:00'));
        return response()->json(['data'=>[
            'total_revenue_irr'=>(int)(clone $payments)->sum('p.amount_irr'),
            'center_revenue_irr'=>$balance('center_revenue'),'counselor_payable_irr'=>$counselorPayable,
            'paid_settlements_irr'=>(int)(clone $settlements)->sum('amount_irr'),'pending_settlements_irr'=>0,
            'refunds_irr'=>$refunds,'service_revenue'=>$byService,'counselor_revenue'=>$byCounselor,'daily_revenue'=>$byDate,
        ]]);
    }

    public function reconcile(Request $r) {
        $v=$r->validate(['counselor_id'=>'nullable|exists:counselors,id']);
        $paid=DB::table('booking_payments')->where('status','verified')
            ->selectRaw('booking_id, SUM(amount_irr) AS verified_irr')->groupBy('booking_id');
        $mismatches=DB::table('bookings as b')->leftJoinSub($paid,'p','p.booking_id','=','b.id')
            ->when($v['counselor_id']??null,fn($q,$id)=>$q->where('b.counselor_id',$id))
            ->whereRaw('b.paid_irr <> COALESCE(p.verified_irr,0)')
            ->select('b.id','b.counselor_id','b.paid_irr')->selectRaw('COALESCE(p.verified_irr,0) AS verified_irr')
            ->orderBy('b.id')->limit(100)->get();
        $ledger=DB::table('booking_ledger_entries as l')->join('bookings as b','b.id','=','l.booking_id')
            ->when($v['counselor_id']??null,fn($q,$id)=>$q->where('b.counselor_id',$id))
            ->select('l.event_key','l.booking_id')
            ->selectRaw("SUM(CASE WHEN l.direction='debit' THEN l.amount_irr ELSE 0 END) AS debit_irr")
            ->selectRaw("SUM(CASE WHEN l.direction='credit' THEN l.amount_irr ELSE 0 END) AS credit_irr")
            ->groupBy('l.event_key','l.booking_id')
            ->havingRaw('debit_irr <> credit_irr')->limit(100)->get();
        return response()->json(['data'=>['payment_mismatches'=>$mismatches,'unbalanced_ledger_events'=>$ledger]]);
    }
    public function payable(Request $r,int $counselor) {
        abort_unless(DB::table('counselors')->where('id',$counselor)->exists(),404);
        $earned=(int)DB::table('booking_ledger_entries as l')->join('bookings as b','b.id','=','l.booking_id')
            ->where('b.counselor_id',$counselor)->where('l.account','counselor_payable')->where('l.direction','credit')->sum('l.amount_irr');
        $reversed=(int)DB::table('booking_ledger_entries as l')->join('bookings as b','b.id','=','l.booking_id')
            ->where('b.counselor_id',$counselor)->where('l.account','counselor_payable')->where('l.direction','debit')->sum('l.amount_irr');
        $settled=(int)DB::table('booking_settlements')->where('counselor_id',$counselor)->sum('amount_irr');
        return response()->json(['data'=>['earned_irr'=>$earned,'debited_irr'=>$reversed,'settled_irr'=>$settled,
            'payable_irr'=>max(0,$earned-$reversed-$settled)]]);
    }
    public function settlements(Request $r) {
        $v=$r->validate(['counselor_id'=>'nullable|exists:counselors,id']);
        return response()->json(['data'=>DB::table('booking_settlements')
            ->when($v['counselor_id']??null,fn($q,$id)=>$q->where('counselor_id',$id))->orderByDesc('id')->paginate(30)]);
    }
    public function recordSettlement(Request $r) {
        $v=$r->validate(['counselor_id'=>'required|exists:counselors,id','amount_irr'=>'required|integer|min:1',
            'reference'=>'required|string|max:100|unique:booking_settlements,reference','note'=>'nullable|string|max:2000']);
        return DB::transaction(function()use($r,$v) {
            DB::table('counselors')->where('id',$v['counselor_id'])->lockForUpdate()->first();
            abort_if(DB::table('booking_refunds as f')->join('bookings as b','b.id','=','f.booking_id')
                ->where('b.counselor_id',$v['counselor_id'])->whereIn('f.status',['requested','approved','processing'])->exists(),
                409,'Resolve pending refunds before settlement');
            $earned=(int)DB::table('booking_ledger_entries as l')->join('bookings as b','b.id','=','l.booking_id')
                ->where('b.counselor_id',$v['counselor_id'])->where('l.account','counselor_payable')->where('l.direction','credit')->sum('l.amount_irr');
            $debited=(int)DB::table('booking_ledger_entries as l')->join('bookings as b','b.id','=','l.booking_id')
                ->where('b.counselor_id',$v['counselor_id'])->where('l.account','counselor_payable')->where('l.direction','debit')->sum('l.amount_irr');
            $settled=(int)DB::table('booking_settlements')->where('counselor_id',$v['counselor_id'])->sum('amount_irr');
            if($v['amount_irr']>$earned-$debited-$settled) throw ValidationException::withMessages(['amount_irr'=>'Exceeds payable balance']);
            $id=DB::table('booking_settlements')->insertGetId([...$v,'recorded_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'action'=>'settlement.recorded',
                'details'=>json_encode(['settlement_id'=>$id,'counselor_id'=>$v['counselor_id'],'amount_irr'=>$v['amount_irr']]),'created_at'=>now(),'updated_at'=>now()]);
            $remaining=(int)$v['amount_irr'];
            $balances=DB::table('booking_ledger_entries as l')->join('bookings as b','b.id','=','l.booking_id')
                ->where('b.counselor_id',$v['counselor_id'])->where('l.account','counselor_payable')
                ->select('b.id')->selectRaw("SUM(CASE WHEN l.direction='credit' THEN l.amount_irr ELSE 0 END) - SUM(CASE WHEN l.direction='debit' THEN l.amount_irr ELSE 0 END) AS balance")
                ->groupBy('b.id')->havingRaw('balance > 0')->orderBy('b.id')->get();
            foreach($balances as $balance) {
                if($remaining===0) break;
                $amount=min($remaining,(int)$balance->balance);
                $event='settlement:'.$id.':'.$balance->id;
                foreach([['counselor_payable','debit'],['cash','credit']] as [$account,$direction])
                    DB::table('booking_ledger_entries')->insert(['booking_id'=>$balance->id,'account'=>$account,
                        'direction'=>$direction,'amount_irr'=>$amount,'event_key'=>$event,'created_at'=>now(),'updated_at'=>now()]);
                $remaining-=$amount;
            }
            if($remaining!==0) throw ValidationException::withMessages(['amount_irr'=>'Settlement allocation failed']);
            return response()->json(['data'=>DB::table('booking_settlements')->find($id)],201);
        },3);
    }
    public function markRefunded(Request $r,int $refund) {
        $v=$r->validate(['reference'=>'required|string|max:255|unique:booking_refunds,transfer_reference']);
        return DB::transaction(function()use($refund,$v,$r) {
            $row=DB::table('booking_refunds')->where('id',$refund)->lockForUpdate()->first(); abort_unless($row,404);
            abort_unless($row->status==='approved',409);
            $booking=DB::table('bookings')->where('id',$row->booking_id)->lockForUpdate()->first();
            $counselorId=(int)$booking->counselor_id;
            DB::table('counselors')->where('id',$counselorId)->lockForUpdate()->first();
            // The transfer is simulated for fake_bank; real gateways remain unavailable in this phase.
            DB::table('booking_refunds')->where('id',$refund)->update(['status'=>'refunded','transfer_reference'=>$v['reference'],'updated_at'=>now()]);
            $event='refund:'.$refund;
            $amount=(int)$row->refund_amount_irr;
            if($amount>0) {
                $commission=min($amount,intdiv((int)$booking->commission_total_irr*$amount,max(1,(int)$booking->total_irr)));
                $tax=min(max(0,$amount-$commission),intdiv((int)$booking->tax_irr*$amount,max(1,(int)$booking->total_irr)));
                $payable=(int)DB::table('booking_ledger_entries')->where('booking_id',$booking->id)->where('account','counselor_payable')
                    ->selectRaw("SUM(CASE WHEN direction='credit' THEN amount_irr ELSE -amount_irr END) AS balance")->value('balance');
                $payableDebit=min(max(0,$payable),$commission); $recoverable=$commission-$payableDebit;
                $center=$amount-$commission-$tax;
                foreach([['counselor_payable','debit',$payableDebit],['counselor_recoverable','debit',$recoverable],
                    ['center_revenue','debit',$center],['tax_liability','debit',$tax],['cash','credit',$amount]] as [$account,$direction,$sum])
                    if($sum>0) DB::table('booking_ledger_entries')->insert(['booking_id'=>$row->booking_id,
                        'account'=>$account,'direction'=>$direction,'amount_irr'=>$sum,
                        'event_key'=>$event,'created_at'=>now(),'updated_at'=>now()]);
            }
            DB::table('booking_audit_events')->insert(['actor_id'=>$r->user()->id,'booking_id'=>$row->booking_id,'action'=>'refund.completed',
                'details'=>json_encode(['refund_id'=>$refund,'reference'=>$v['reference'],'amount_irr'=>$amount]),'created_at'=>now(),'updated_at'=>now()]);
            return response()->json(['data'=>['id'=>$refund,'status'=>'refunded','reference'=>$v['reference']]]);
        },3);
    }
}
