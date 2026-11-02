# Scheduled SMS reminders

## Implementation

Multiple active rules can schedule relative reminders (for example 24, 12, 6, or 2 hours before) and fixed Asia/Tehran local times on the appointment date (for example 08:00). Each rule selects recipients, appointment modes, message template, quiet hours and retry policy. Defaults are created disabled: 24 hours before and 08:00 on appointment day.

The `booking:schedule-sms-reminders` command runs every minute through Laravel Scheduler. It stores reminders before dispatch, uses a unique booking/rule/recipient/appointment-time key, checks booking state and current schedule before delivery, cancels obsolete reminders after rescheduling/cancellation, and queues due work. Jobs lock the reminder and booking, verify SMS consent and phone availability, record each attempt, use retry/backoff, and prevent duplicate finalization. Quiet-hour adjustments that would occur at or after the appointment are skipped.

`BOOKING_SMS_DRIVER=log` is the default and never sends a message. `fake` records a simulated delivery only in local/testing environments. No real SMS provider is configured. The configured adapter fails closed until a documented provider is selected and configured.

## API

All administrative routes require Sanctum plus `settings.manage`.

| Method | Path | Purpose |
|---|---|---|
| GET, POST | `/api/admin/settings/sms-reminders/rules` | List paginated rules / create a rule |
| PATCH, DELETE | `/api/admin/settings/sms-reminders/rules/{rule}` | Update / safely delete a rule (history prevents deletion; disable instead) |
| POST | `/api/admin/settings/sms-reminders/rules/{rule}/preview` | Render a message against a booking without sending |
| GET | `/api/admin/settings/sms-reminders/scheduled` | Filter and paginate scheduled items |
| GET | `/api/admin/settings/sms-reminders/history` | Filter and paginate delivery history |
| GET | `/api/admin/settings/sms-reminders/attempts?reminder_id=…` | Inspect attempt/provider history |
| POST | `/api/admin/settings/sms-reminders/{reminder}/retry` | Retry failed or unconfigured item (202) |
| POST | `/api/admin/settings/sms-reminders/{reminder}/cancel` | Cancel pending item (204) |
| GET, PUT | `/api/account/notification-preferences` | Read/update current user's SMS consent |

Create body: `name`, `timing_type` (`relative` or `fixed_local`), conditional `minutes_before` or `local_time` (`HH:mm`), nonempty `recipients` (`client`, `counselor`, `reception`), optional `modes` (`in_person`, `meet`, `voice_call`), `template`, optional paired `quiet_start`/`quiet_end`, optional `max_attempts` (1–8), `retry_delay_seconds` (30–86400), and `is_active`. The user's explicit SMS preference is required before scheduling and checked again immediately before sending.

Template variables: `{{client_name}}`, `{{counselor_name}}`, `{{appointment_date}}`, `{{appointment_time}}`, `{{session_type}}`, `{{center_name}}`, `{{booking_reference}}`, `{{center_address}}`, and `{{service_name}}`. Clinical content and early session connection details are not included.

## Queue setup

Run Laravel Scheduler (`php artisan schedule:work` locally or the standard one-minute scheduler in deployment) and a queue worker (`php artisan queue:work`). `QUEUE_CONNECTION=database` is the configured default. Ensure standard Laravel queue tables exist in the target environment before enabling workers. Real delivery remains disabled until an approved provider adapter/configuration is installed.
