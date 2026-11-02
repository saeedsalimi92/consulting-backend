<?php
namespace App\Services\Payments;

class FakeBankGateway implements PaymentGateway
{
    public function initiate(int $amountIrr, string $callbackUrl): array
    {
        return ['authority' => 'fb_'.bin2hex(random_bytes(18)), 'url' => $callbackUrl];
    }

    public function verify(string $authority, int $amountIrr): ?string
    {
        // A fake bank response is only accepted through the token-protected checkout endpoint.
        return str_starts_with($authority, 'fb_') ? 'test_'.strtoupper(bin2hex(random_bytes(8))) : null;
    }
}
