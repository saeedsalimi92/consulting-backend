<?php
namespace App\Services\Payments;
interface PaymentGateway {
    public function initiate(int $amountIrr, string $callbackUrl): array;
    public function verify(string $authority, int $amountIrr): ?string;
}
