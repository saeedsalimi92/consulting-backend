<?php
namespace App\Services;
use Illuminate\Validation\ValidationException;
class BookingMoney {
    public static function commission(int $price,string $type,string $value): int {
        if($price<0 || $price>1000000000) throw ValidationException::withMessages(['price_irr'=>'Price outside supported range']);
        if($type==='fixed') {
            if(!preg_match('/^\d+(?:\.00)?$/',$value)) throw ValidationException::withMessages(['commission_value'=>'Fixed commission must be whole IRR']);
            $amount=(int)$value;
        } else {
            if(!preg_match('/^\d+(?:\.\d{1,2})?$/',$value)) throw ValidationException::withMessages(['commission_value'=>'Percentage may have two decimals']);
            [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
            $basisPoints=(int)$whole*100+(int)str_pad($fraction,2,'0');
            if($basisPoints>10000) throw ValidationException::withMessages(['commission_value'=>'Maximum 100 percent']);
            $amount=intdiv($price*$basisPoints+5000,10000); // half up, whole IRR
        }
        if($amount>$price) throw ValidationException::withMessages(['commission_value'=>'Commission exceeds price']);
        return $amount;
    }
}
