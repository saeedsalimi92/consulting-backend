<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class BookingPricing
{
    public static function snapshot(int $serviceId,int $basePrice,string $commissionType,string $commissionValue,string $option,int $deposit): array
    {
        $rule=DB::table('booking_service_tax_rules')->where('booking_service_id',$serviceId)->where('enabled',true)
            ->where('effective_from','<=',now(config('booking.session_timezone','Asia/Tehran'))->toDateString())
            ->where(fn($q)=>$q->whereNull('effective_to')->orWhere('effective_to','>=',now(config('booking.session_timezone','Asia/Tehran'))->toDateString()))
            ->orderByDesc('effective_from')->first();
        $bps=(int)($rule->rate_basis_points??0); $tax=0; $total=$basePrice;
        if($rule && $bps>0) {
            $tax=$rule->inclusive ? intdiv($basePrice*$bps,10000+$bps) : intdiv($basePrice*$bps,10000);
            if(!$rule->inclusive) $total+=$tax;
        }
        $depositTotal=$basePrice>0 ? intdiv($deposit*$total+$basePrice-1,$basePrice) : 0;
        $required=$option==='deposit' ? min($total,$depositTotal) : $total;
        $netBase=$rule && $rule->inclusive ? $basePrice-$tax : $basePrice;
        $commission=BookingMoney::commission($netBase,$commissionType,$commissionValue);
        return ['total_irr'=>$total,'required_irr'=>$required,'tax_irr'=>$tax,'tax_rate_basis_points'=>$bps,
            'base_price_irr'=>$netBase,'commission_total_irr'=>$commission,
            'center_share_irr'=>max(0,$total-$tax-$commission)];
    }
}
