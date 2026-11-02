# Fake Bank browser flow

The client submits `POST /api/booking/my-reservations/{booking}/payments` with `{amount_irr,idempotency_key}`. The response has `{data:{payment_id,url,checkout_token,expires_at}}`. Redirect the browser to `url`; Laravel renders the local test payment page with booking reference and amount and exactly two actions: `پرداخت موفق` and `پرداخت ناموفق`.

The page posts the opaque token and selected result to `POST /api/booking/fake-bank/payments/{payment_id}/result`. On success, backend verification updates the ledger, receipt and booking; on failure, the attempt is persisted as failed and the booking remains unpaid. The page redirects only to server-configured `BOOKING_FAKE_BANK_RETURN_URL` (default `http://localhost:3000/fake-bank`) with `payment_id` and a display status query value. Frontend must fetch payment status/receipt from the authenticated client API; redirect parameters are not proof of payment.

All fake-bank routes reject non-local/non-testing environments. No arbitrary amount or redirect destination is accepted at the result endpoint. Fake payment URLs expire with the checkout token.
