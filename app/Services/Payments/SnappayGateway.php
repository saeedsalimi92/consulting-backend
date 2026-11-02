<?php
namespace App\Services\Payments;
class SnappayGateway implements PaymentGateway {
    public function initiate(int $amountIrr,string $callbackUrl): array {
        abort(503,'Snappay merchant integration is not configured');
    }
    public function verify(string $authority,int $amountIrr): ?string {
        abort(503,'Snappay merchant verification is not configured');
    }
}
