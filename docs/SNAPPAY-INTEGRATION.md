# SnappPay integration status

`SnappayGateway` is registered in the existing payment gateway abstraction, but both initiation and verification fail closed with HTTP 503 until the provider contract is implemented. Admin gateway reporting marks it unavailable. No provider success, repayment schedule, merchant commission or lender terms are simulated.

To complete safely, obtain official merchant documentation for checkout creation, eligibility (if applicable), callback signing, server-side confirmation, cancellation/expiry, refund, reconciliation, idempotency, and sandbox credentials. Then implement and integration-test each documented operation and activate only after explicit business approval.
