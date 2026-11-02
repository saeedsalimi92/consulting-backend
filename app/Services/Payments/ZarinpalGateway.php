<?php
namespace App\Services\Payments;
class ZarinpalGateway implements PaymentGateway {
    public function initiate(int $amountIrr,string $callbackUrl): array {
        abort(503,'Zarinpal merchant integration is not configured');
    }
    public function verify(string $authority,int $amountIrr): ?string {
        abort(503,'Zarinpal merchant verification is not configured');
    }
}
