<?php
namespace App\Services\Payments;
class MockGateway implements PaymentGateway {
    public function initiate(int $amountIrr,string $callbackUrl): array {
        return ['authority'=>'mock-'.bin2hex(random_bytes(16)),'url'=>$callbackUrl];
    }
    public function verify(string $authority,int $amountIrr): ?string {
        return str_starts_with($authority,'mock-') ? 'verified-'.$authority : null;
    }
}
