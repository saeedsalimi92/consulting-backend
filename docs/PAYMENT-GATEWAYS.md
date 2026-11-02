# Payment gateway status

All critical amounts are persisted in integer IRR. Payment initiation accepts the booking and amount due, snapshots an attempt, and uses an idempotency key. Verified payments flow through `BookingPayments::applyVerified`, which updates booking paid/status values, receipt and ledger in a transaction. Callbacks are provider-specific; Fake Bank cannot be verified from callback query parameters.

| Provider | State | Notes |
|---|---|---|
| Fake Bank | Implemented for `local`/`testing` | Token-bound browser checkout, success/failure, idempotent result and local ledger. Production rejects initiation and UI routes. |
| Zarinpal | External blocked | Adapter fails closed. Credentials/config are not returned through settings. Require official merchant contract and sandbox test before activation. |
| SnappPay | External blocked | Adapter fails closed. Require official installment checkout/confirmation/callback and refund contract; no installment terms or commissions are assumed. |

Admin gateway endpoint: `GET /api/admin/booking/gateways`; updates are under `PUT /api/admin/booking/gateways` with `finance.manage`. The API reports unsupported integrations as not ready. Do not enable a provider just by changing a database flag.
