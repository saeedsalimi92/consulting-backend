<?php
namespace App\Http\Controllers\Booking;
use App\Http\Controllers\Controller;
use App\Services\BookingPayments;
use App\Services\BookingNotices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class PaymentController extends Controller {
    public function mine(Request $r,int $booking) {
        abort_unless(DB::table('bookings')->where('id',$booking)->where('user_id',$r->user()->id)->exists(),404);
        return response()->json(['data'=>DB::table('booking_payments')->where('booking_id',$booking)
            ->select('id','provider','status','amount_irr','payment_method','provider_reference','verified_at','created_at')
            ->orderByDesc('id')->get()]);
    }
    public function receipts(Request $r,int $booking) {
        abort_unless(DB::table('bookings')->where('id',$booking)->where('user_id',$r->user()->id)->exists(),404);
        return response()->json(['data'=>DB::table('booking_receipts')->where('booking_id',$booking)->orderBy('id')->get()]);
    }
    public function initiate(Request $r,int $booking,BookingPayments $payments) {
        $v=$r->validate(['idempotency_key'=>'required|string|max:100','amount_irr'=>'required|integer|min:1']);
        $row=DB::table('bookings')->where('id',$booking)->where('user_id',$r->user()->id)->first();
        abort_unless($row,404);
        $existing=DB::table('booking_payments')->where('idempotency_key',$v['idempotency_key'])->first();
        if($existing) {
            $active=DB::table('booking_gateway_settings')->where('enabled',true)->whereIn('provider',['fake_bank','zarinpal'])->value('provider') ?: config('booking.payment_driver');
            abort_unless((int)$existing->booking_id===$booking && (int)$existing->amount_irr===$v['amount_irr']
                && $existing->provider===$active,409);
            return response()->json(['data'=>['payment_id'=>$existing->id,'booking_id'=>$existing->booking_id,
                'provider'=>$existing->provider,'status'=>$existing->status,'amount_irr'=>(int)$existing->amount_irr,
                'provider_reference'=>$existing->provider_reference,'verified_at'=>$existing->verified_at,
                'created_at'=>$existing->created_at]]);
        }
        abort_unless(in_array($row->status,['held','confirmed'],true),409);
        abort_if(DB::table('booking_refunds')->where('booking_id',$booking)->whereIn('status',['requested','approved','processing','refunded'])->exists(),409,'Cancellation under review');
        abort_if($row->status==='held' && $row->hold_expires_at<=now()->utc()->toDateTimeString(),409,'Reservation expired');
        abort_if($v['amount_irr']>(int)$row->total_irr-(int)$row->paid_irr,422,'Amount exceeds balance');
        abort_if($row->status==='held' && $v['amount_irr']<(int)$row->required_irr-(int)$row->paid_irr,422,'Amount below required payment');
        $provider=DB::table('booking_gateway_settings')->where('enabled',true)->whereIn('provider',['fake_bank','zarinpal'])->value('provider') ?: config('booking.payment_driver');
        if ($provider==='fake_bank') abort_unless(app()->environment(['local','testing']),503,'Fake Bank is available only in local/testing environments.');
        $gateway=$payments->gateway();
        abort_unless($provider==='fake_bank',503,'Selected gateway is not implemented');
        $init=$gateway->initiate($v['amount_irr'],route('booking.payment.callback'));
        $token=Str::random(64);
        $expires=now()->addMinutes(max(1,(int)config('booking.checkout_token_minutes',20)));
        try {
            $id=DB::table('booking_payments')->insertGetId(['booking_id'=>$booking,'provider'=>$provider,'status'=>'pending',
                'amount_irr'=>$v['amount_irr'],'provider_authority'=>$init['authority'],
                'idempotency_key'=>$v['idempotency_key'],'checkout_token_hash'=>hash('sha256',$token),
                'checkout_expires_at'=>$expires,'created_at'=>now(),'updated_at'=>now()]);
        } catch(QueryException $e) {
            if($e->getCode()!=='23000') throw $e;
            $existing=DB::table('booking_payments')->where('idempotency_key',$v['idempotency_key'])->first();
            if(!$existing) throw $e;
            $active=DB::table('booking_gateway_settings')->where('enabled',true)->whereIn('provider',['fake_bank','zarinpal'])->value('provider') ?: config('booking.payment_driver');
            abort_unless((int)$existing->booking_id===$booking && (int)$existing->amount_irr===$v['amount_irr']
                && $existing->provider===$active,409);
            return response()->json(['data'=>['payment_id'=>$existing->id,'booking_id'=>$existing->booking_id,
                'provider'=>$existing->provider,'status'=>$existing->status,'amount_irr'=>(int)$existing->amount_irr,
                'provider_reference'=>$existing->provider_reference,'verified_at'=>$existing->verified_at,
                'created_at'=>$existing->created_at]]);
        }
        $url=$provider==='fake_bank'
            ? url('/api/booking/fake-bank/checkout/'.$id.'?token='.urlencode($token))
            : (rtrim((string)config('booking.checkout_url'),'/') ?: null);
        return response()->json(['data'=>['payment_id'=>$id,'url'=>$url,'checkout_token'=>$token,'expires_at'=>$expires->toIso8601String()]],201);
    }
    public function fakeCheckout(Request $r,int $payment) {
        abort_unless(app()->environment(['local','testing']),404);
        $v=$r->validate(['token'=>'required|string|size:64']);
        $row=DB::table('booking_payments as p')->join('bookings as b','b.id','=','p.booking_id')
            ->join('booking_services as s','s.id','=','b.booking_service_id')->where('p.id',$payment)->first([
                'p.id','p.booking_id','p.amount_irr','p.status','p.provider','p.checkout_token_hash','p.checkout_expires_at',
                'b.status as booking_status','s.title as service_title']);
        abort_unless($row && $row->provider==='fake_bank' && $row->checkout_token_hash
            && hash_equals($row->checkout_token_hash,hash('sha256',$v['token'])),404);
        abort_if($row->checkout_expires_at<=now()->toDateTimeString(),410,'Checkout expired');
        abort_unless(in_array($row->status,['pending','created'],true) && in_array($row->booking_status,['held','confirmed'],true),409);
        return response()->json(['data'=>['payment_id'=>$row->id,'booking_id'=>$row->booking_id,
            'booking_reference'=>'BK-'.$row->booking_id,'service_name'=>$row->service_title,
            'amount_irr'=>(int)$row->amount_irr,'provider'=>'fake_bank','label'=>'TEST PAYMENT']]);
    }
    public function fakeCheckoutPage(Request $r,int $payment) {
        abort_unless(app()->environment(['local','testing']),404);
        $data=$this->fakeCheckout($r,$payment)->getData(true)['data'];
        return response()->view('fake-bank-checkout',['payment'=>$data,'token'=>$r->query('token'),
            'resultUrl'=>url('/api/booking/fake-bank/payments/'.$payment.'/result'),
            'returnUrl'=>config('booking.fake_bank_return_url')]);
    }
    public function fakeResult(Request $r,int $payment,BookingPayments $payments,BookingNotices $notices) {
        abort_unless(app()->environment(['local','testing']),404);
        $v=$r->validate(['token'=>'required|string|size:64','result'=>'required|in:success,failure']);
        $row=DB::table('booking_payments')->where('id',$payment)->first();
        abort_unless($row && $row->provider==='fake_bank' && $row->checkout_token_hash
            && hash_equals($row->checkout_token_hash,hash('sha256',$v['token'])),404);
        abort_if(!$row->checkout_expires_at || $row->checkout_expires_at<=now()->toDateTimeString(),410,'Checkout expired');
        // The opaque checkout credential authorizes viewing this one attempt until expiry.
        // A result retry is idempotent: return the persisted outcome without posting again.
        if($row->status==='verified' && $v['result']==='success') {
            return response()->json(['data'=>['payment_id'=>$payment,'booking_id'=>$row->booking_id,
                'status'=>'verified','booking_status'=>DB::table('bookings')->where('id',$row->booking_id)->value('status'),
                'receipt'=>DB::table('booking_receipts')->where('payment_id',$payment)->value('receipt_number')]]);
        }
        if($row->status==='failed' && $v['result']==='failure')
            return response()->json(['data'=>['payment_id'=>$payment,'booking_id'=>$row->booking_id,'status'=>'failed']]);
        if($v['result']==='failure') {
            DB::transaction(function()use($payment) {
                $p=DB::table('booking_payments')->where('id',$payment)->lockForUpdate()->first();
                abort_unless(in_array($p->status,['pending','created'],true),409);
                DB::table('booking_payments')->where('id',$payment)->update(['status'=>'failed','provider_metadata'=>json_encode(['test_result'=>'failure']),'updated_at'=>now()]);
            });
            $notices->queue((int)$row->booking_id,'payment-failed-'.$payment,'client','in_app');
            return response()->json(['data'=>['payment_id'=>$payment,'status'=>'failed','booking_id'=>$row->booking_id]]);
        }
        $reference=$payments->gatewayFor('fake_bank')->verify($row->provider_authority,(int)$row->amount_irr);
        abort_unless($reference,422,'Test verification failed');
        $booking=$payments->applyVerified($payment,$reference);
        $notices->queue($booking->id,'payment-'.$payment,'client','in_app');
        $notices->queue($booking->id,'confirmed','client','in_app');
        return response()->json(['data'=>['payment_id'=>$payment,'booking_id'=>$booking->id,'status'=>'verified','booking_status'=>$booking->status,
            'receipt'=>DB::table('booking_receipts')->where('payment_id',$payment)->value('receipt_number')]]);
    }
    public function callback(Request $r,BookingPayments $payments,BookingNotices $notices) {
        $v=$r->validate(['authority'=>'required|string|max:255']);
        $payment=DB::table('booking_payments')->where('provider_authority',$v['authority'])->first();
        abort_unless($payment,404);
        abort_if($payment->provider==='fake_bank',403,'Fake bank results must use the issued checkout token');
        $reference=$payments->gatewayFor($payment->provider)->verify($payment->provider_authority,(int)$payment->amount_irr);
        abort_unless($reference,422,'Verification failed');
        $booking=$payments->applyVerified($payment->id,$reference);
        $notices->queue($booking->id,'payment-'.$payment->id,'client');
        if($booking->status==='confirmed') $notices->queue($booking->id,'confirmed','client');
        return response()->json(['data'=>['booking_id'=>$booking->id,'status'=>$booking->status,'paid_irr'=>$booking->paid_irr]]);
    }
    public function recordInPerson(Request $r,int $booking,BookingPayments $payments,BookingNotices $notices) {
        $v=$r->validate(['idempotency_key'=>'required|string|max:100','amount_irr'=>'required|integer|min:1',
            'payment_method'=>'required|in:cash,pos',
            'reference'=>'required|string|max:255']);
        $existing=DB::table('booking_payments')->where('idempotency_key',$v['idempotency_key'])->first();
        if($existing) {
            abort_unless((int)$existing->booking_id===$booking && (int)$existing->amount_irr===$v['amount_irr']
                && $existing->provider==='in_person' && $existing->payment_method===$v['payment_method']
                && $existing->provider_reference===$v['reference'],409);
            return response()->json(['data'=>$existing]);
        }
        try { [$id,$confirmed]=DB::transaction(function()use($r,$booking,$v,$payments) {
            $reservation=DB::table('bookings')->where('id',$booking)->first();
            abort_unless($reservation,404);
            abort_unless($reservation->mode==='in_person',409,'Cash/POS is limited to in-person bookings');
            $id=DB::table('booking_payments')->insertGetId(['booking_id'=>$booking,'provider'=>'in_person','status'=>'pending',
                'amount_irr'=>$v['amount_irr'],'payment_method'=>$v['payment_method'],
                'idempotency_key'=>$v['idempotency_key'],'recorded_by'=>$r->user()->id,
                'created_at'=>now(),'updated_at'=>now()]);
            return [$id,$payments->applyVerified($id,$v['reference'])];
        },3); } catch(QueryException $e) {
            if($e->getCode()!=='23000') throw $e;
            $existing=DB::table('booking_payments')->where('idempotency_key',$v['idempotency_key'])->first();
            if(!$existing) throw $e;
            abort_unless((int)$existing->booking_id===$booking && (int)$existing->amount_irr===$v['amount_irr']
                && $existing->provider==='in_person' && $existing->payment_method===$v['payment_method']
                && $existing->provider_reference===$v['reference'],409);
            return response()->json(['data'=>$existing]);
        }
        $notices->queue($confirmed->id,'payment-'.$id,'client');
        if($confirmed->status==='confirmed') $notices->queue($confirmed->id,'confirmed','client');
        return response()->json(['data'=>DB::table('booking_payments')->find($id)],201);
    }
}
