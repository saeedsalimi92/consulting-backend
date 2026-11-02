# Backend completion tracker

Status separates implementation from verification. `IMPLEMENTED` means the internal backend path exists; it does not mean an external provider or production service was activated.

| Module | Implementation | Verification | Current note |
|---|---|---|---|
| Authentication and account security | PARTIAL | UNIT_TESTED | Sanctum login/register/reset, session, password change, logout-all and token revocation are present. Finance authenticates through admin roles; some account/profile workflows remain role-specific. |
| Roles, permissions and user access | IMPLEMENTED | UNIT_TESTED | Role/permission operations and protected admin user access exist; final cross-role MySQL security review remains. |
| Counselors, services and specialties | PARTIAL | UNIT_TESTED | CRUD, public profile visibility, specialties, service association/removal, prices/commission fields, shifts and leave exist. Full business-contract reconciliation and counselor-specific pricing/commission reconciliation remain. |
| Booking, reception and waitlist | IMPLEMENTED | UNIT_TESTED | Booking lifecycle, calendar, filters, reception check-in, cancellation/reschedule and expiring waitlist offers exist. MySQL concurrency remains unverified. |
| Rooms, schedules and capacity | IMPLEMENTED | UNIT_TESTED | Room CRUD/calendar, weekly hours, maintenance, holidays, room assignment, capacity validation and operational capacity calculation are present. MySQL concurrent allocation and business metric sign-off remain. |
| Clinical CRM and counselor workspace | IMPLEMENTED | UNIT_TESTED | Intake/forms, consent, scoped notes/versioning, attachments and follow-up functions exist. Final role-by-role privacy review remains. |
| Session delivery | IMPLEMENTED | UNIT_TESTED | In-person, external Meet and telephone session metadata, guarded release and lifecycle exist; no embedded conferencing. |
| Finance and accounting | PARTIAL | UNIT_TESTED | IRR ledger, payments, refunds, settlements and idempotency exist. Full financial reconciliation and final exports validation remain. |
| Fake Bank | IMPLEMENTED | UNIT_TESTED | Local/testing-only checkout page and idempotent simulated success/failure; no production operation. |
| Zarinpal | BLOCKED_EXTERNAL | STATIC_CHECKED | Fail-closed until current official contract, merchant credentials and sandbox are provided. |
| SnappPay | BLOCKED_EXTERNAL | STATIC_CHECKED | Fail-closed until merchant contract, official flow documentation, credentials and sandbox are provided. |
| SMS reminder rules and scheduler | IMPLEMENTED | UNIT_TESTED | Multi-rule Tehran-local scheduling, persistence, consent, quiet hours, queue, idempotent claim, cancellation/reschedule invalidation, retry history and manual retry exist. Real SMS provider remains blocked and disabled. |
| Other notification channels | PARTIAL | UNIT_TESTED | In-app inbox and existing notification pipeline exist. Provider-specific email/SMS delivery and delivery guarantees need configured providers and final QA. |
| XLSX reports | IMPLEMENTED | UNIT_TESTED | Eight real OpenXML exports stream/chunk rows and require PHP `zip` and `xmlwriter`. Large production dataset performance remains final QA. |
| CMS | PARTIAL | UNIT_TESTED | Existing CMS models, publication visibility, ordering, SEO, category filtering and private media library are reused. Media listing and reference-safe deletion are implemented; content-type-specific editorial fields still need business-contract reconciliation. |
| Settings | PARTIAL | UNIT_TESTED | Center profile/hours/holidays, booking policies, gateway capability, SMS driver and SEO defaults are exposed. Credentials are not returned; Zarinpal/SnappPay configuration remains external. |
| Audit and privacy | PARTIAL | UNIT_TESTED | Sensitive access/changes are audited across implemented operations; full audit coverage review remains. |
| OpenAPI / Swagger | PARTIAL | STATIC_CHECKED | Route-derived OpenAPI 3.0.3 has validation-derived request fields, auth/role annotations, endpoint response schemas for core booking/finance/clinical/CMS/reminder/export flows, pagination and common errors. Legacy endpoints still include generic response schemas; no 100% response-shape coverage claim. |

Latest local verification: **59 tests, 510 assertions, passing** (PHPUnit in-memory SQLite); XLSX and CMS/counselor visibility coverage are included. PHP 8.2.34 was used. MySQL 8.4 concurrency, browser E2E and live provider tests were not run. Migration `2026_10_14_000008_add_counselor_public_visibility.php` is additive and was not run against any database in this pass; no migrations were executed against `consulting`.
# Authentication / OTP / profile gate update (2026-10-15)

- [x] OTP request/verify/resend routes, persistent cooldown/hourly quotas, one-use expiry and attempt controls
- [x] Google reCAPTCHA v3 server verifier with production fail-closed behavior
- [x] Password login compatibility and optional password setup after recent OTP
- [x] Profile completeness service and client booking enforcement
- [x] SMS.ir provider boundary and configuration; **live HTTP dispatch remains blocked** pending official API docs and credentials
- [x] Auth/profile OpenAPI and frontend integration documentation
- [x] Legacy public registration route retained but deprecated with HTTP 410; no user creation bypass remains
- [x] Unified mobile-login state machine and anonymous account-enumeration rationale documented
- [ ] Run additive migrations only on reviewed isolated MySQL, then verify concurrency/database semantics
- [ ] Live reCAPTCHA and SMS.ir staging verification with clinic-owned credentials
