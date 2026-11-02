<?php

namespace Tests\Unit;

use App\Services\BookingMoney;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingMoneyTest extends TestCase
{
    #[DataProvider('commissionCases')]
    public function test_commission_uses_whole_irr_rounding(int $price,string $type,string $value,int $expected): void
    {
        $this->assertSame($expected,BookingMoney::commission($price,$type,$value));
    }

    public static function commissionCases(): array
    {
        return [
            'percentage half up'=>[1001,'percentage','12.50',125],
            'fixed whole IRR'=>[100000,'fixed','25000',25000],
            'zero percentage'=>[100000,'percentage','0',0],
            'full percentage'=>[100000,'percentage','100',100000],
        ];
    }

    public function test_commission_rejects_amounts_above_price_and_fractional_fixed_amounts(): void
    {
        try { BookingMoney::commission(100,'fixed','101'); $this->fail('Expected commission over price to fail'); }
        catch(ValidationException) { $this->assertTrue(true); }
        try { BookingMoney::commission(100,'fixed','10.50'); $this->fail('Expected fractional fixed commission to fail'); }
        catch(ValidationException) { $this->assertTrue(true); }
    }
}
