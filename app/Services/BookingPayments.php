<?php
namespace App\Services;
use App\Services\Payments\{FakeBankGateway,MockGateway,PaymentGateway,ZarinpalGateway,SnappayGateway};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\Auth\ProfileCompleteness;

class BookingPayments {
    public function gateway(): PaymentGateway {
        $active=DB::table('booking_gateway_settings')->where('enabled',true)->whereIn('provider',['fake_bank','zarinpal'])->value('provider');
        return $this->gatewayFor($active ?: config('booking.payment_driver'));
    }
    public function gatewayFor(string $provider): PaymentGateway {
        return match($provider) {
            'fake_bank'=>new FakeBankGateway(),
            'mock'=>app()->environment('production')?abort(503,'Mock payments disabled in production'):new MockGateway(),
            'zarinpal'=>new ZarinpalGateway(),
            'snappay'=>new SnappayGateway(),
            default=>abort(503,'Payment gateway not configured'),
        };
    }
    public function applyVerified(int $paymentId,string $reference): object {
        return DB::transaction(function()use($paymentId,$reference) {
            $payment=DB::table('booking_payments')->where('id',$paymentId)->first();
            abort_unless($payment,404);
            $booking=DB::table('bookings')->where('id',$payment->booking_id)->lockForUpdate()->first();
            $payment=DB::table('booking_payments')->where('id',$paymentId)->lockForUpdate()->first();
            if($payment->status==='verified') return $booking;
            abort_unless(in_array($payment->status,['created','pending'],true),409,'Payment not payable');
            abort_unless(in_array($booking->status,['held','confirmed'],true),409,'Booking not payable');
            if ((int)($booking->created_by ?? 0) === (int)$booking->user_id) {
                $owner=\App\Models\User::whereKey($booking->user_id)->lockForUpdate()->first();
                if ($owner && $owner->canAccessClientPortal()) app(ProfileCompleteness::class)->assertComplete($owner);
            }
            abort_if(DB::table('booking_refunds')->where('booking_id',$booking->id)
                ->whereIn('status',['requested','approved','processing','refunded'])->exists(),409,'Cancellation under review');
            if($booking->status==='held' && $booking->hold_expires_at<=now()->utc()->toDateTimeString())
                abort(409,'Reservation expired');
            $old=(int)$booking->paid_irr; $amount=(int)$payment->amount_irr; $total=(int)$booking->total_irr;
            if($old+$amount>$total) throw ValidationException::withMessages(['amount_irr'=>'Payment exceeds balance']);
            if($booking->status==='held' && $old+$amount<(int)$booking->required_irr)
                throw ValidationException::withMessages(['amount_irr'=>'Amount below required payment']);
            $commission=(int)$booking->commission_total_irr;
            $oldShare=intdiv($commission*$old,$total);
            $newShare=intdiv($commission*($old+$amount),$total);
            $commissionDelta=$newShare-$oldShare;
            $taxTotal=(int)($booking->tax_irr ?? 0);
            $oldTax=intdiv($taxTotal*$old,$total);
            $newTax=intdiv($taxTotal*($old+$amount),$total);
            $taxDelta=$newTax-$oldTax;
            $centerDelta=$amount-$commissionDelta-$taxDelta;
            abort_if($centerDelta<0,409,'Invalid financial snapshots');
            DB::table('booking_payments')->where('id',$paymentId)->update(['status'=>'verified','provider_reference'=>$reference,
                'verified_at'=>now()->utc(),'updated_at'=>now()]);
            DB::table('bookings')->where('id',$booking->id)->update(['paid_irr'=>$old+$amount,
                'status'=>$old+$amount>=(int)$booking->required_irr?'confirmed':$booking->status,'updated_at'=>now()]);
            $event='payment:'.$paymentId;
            foreach([['cash','debit',$amount],['counselor_payable','credit',$commissionDelta],
                ['center_revenue','credit',$centerDelta],['tax_liability','credit',$taxDelta]] as [$account,$direction,$sum]) {
                if($sum>0) DB::table('booking_ledger_entries')->insert(['booking_id'=>$booking->id,'payment_id'=>$paymentId,
                    'account'=>$account,'direction'=>$direction,'amount_irr'=>$sum,'event_key'=>$event,
                    'created_at'=>now(),'updated_at'=>now()]);
            }
            $snapshot=DB::table('bookings as b')->join('booking_services as s','s.id','=','b.booking_service_id')
                ->join('counselors as c','c.id','=','b.counselor_id')->join('users as u','u.id','=','b.user_id')
                ->where('b.id',$booking->id)->first(['b.id as booking_id','b.starts_at','b.ends_at','b.total_irr','b.discount_irr','b.tax_irr',
                    's.title as service_name','c.name as counselor_name','u.name as client_name']);
            $receipt='RC-'.now()->format('Ymd').'-'.str_pad((string)$paymentId,8,'0',STR_PAD_LEFT);
            DB::table('booking_receipts')->insertOrIgnore(['booking_id'=>$booking->id,'payment_id'=>$paymentId,
                'receipt_number'=>$receipt,'amount_irr'=>$amount,'discount_irr'=>(int)$booking->discount_irr,
                'tax_irr'=>$taxDelta,'provider'=>$payment->provider,'provider_reference'=>$reference,
                'paid_at'=>now()->utc(),'snapshot'=>json_encode($snapshot),'created_at'=>now(),'updated_at'=>now()]);
            foreach([(int)$booking->user_id] as $userId) DB::table('booking_in_app_notifications')->insertOrIgnore([
                'user_id'=>$userId,'booking_id'=>$booking->id,'event_key'=>'payment-success-'.$paymentId,
                'title'=>'پرداخت با موفقیت ثبت شد','body'=>'پرداخت رزرو شما ثبت و رسید آن صادر شد.',
                'payload'=>json_encode(['payment_id'=>$paymentId]),'created_at'=>now(),'updated_at'=>now()]);
            $counselorUser=DB::table('counselors')->where('id',$booking->counselor_id)->value('user_id');
            foreach(array_filter([(int)$counselorUser]) as $userId) DB::table('booking_in_app_notifications')->insertOrIgnore([
                'user_id'=>$userId,'booking_id'=>$booking->id,'event_key'=>'payment-success-'.$paymentId,
                'title'=>'پرداخت رزرو ثبت شد','body'=>'یک جلسه جدید به برنامه شما اضافه شد.',
                'payload'=>json_encode(['payment_id'=>$paymentId]),'created_at'=>now(),'updated_at'=>now()]);
            foreach(DB::table('users')->where('is_admin',true)->pluck('id') as $userId) DB::table('booking_in_app_notifications')->insertOrIgnore([
                'user_id'=>(int)$userId,'booking_id'=>$booking->id,'event_key'=>'payment-success-'.$paymentId,
                'title'=>'پرداخت رزرو ثبت شد','body'=>'پرداخت یک رزرو جدید ثبت شد.',
                'payload'=>json_encode(['payment_id'=>$paymentId]),'created_at'=>now(),'updated_at'=>now()]);
            DB::table('booking_audit_events')->insert(['actor_id'=>null,'booking_id'=>$booking->id,'action'=>'payment.verified',
                'details'=>json_encode(['payment_id'=>$paymentId,'provider'=>$payment->provider]),'created_at'=>now(),'updated_at'=>now()]);
            return DB::table('bookings')->find($booking->id);
        },3);
    }
}
