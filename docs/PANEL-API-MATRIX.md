# ماتریس قرارداد API پنل

تمام endpointها زیر `/api` هستند. فهرست بر اساس `routes/api.php` و کد پنل فعلی در زمان 2026-10-13 است. پاسخ‌های موفق معمولاً در `{ "data": ... }` قرار دارند. مجوز نوشتن پنل با token مدیر و permissionهای `EnsureAdmin` کنترل می‌شود؛ مسیرهای counselor با token مشاور scope می‌شوند. فهرست جایگزین OpenAPI تولیدشده نیست.

| قابلیت / صفحه پنل | API | مجوز غالب | قرارداد / وضعیت QA |
|---|---|---|---|
| داشبورد پذیرش `/booking/reception` | `GET /admin/booking/reception/dashboard` | `appointments.manage` | API موجود؛ backend tests PASS، browser E2E BLOCKED |
| تقویم `/booking/calendar` | `GET /admin/booking/calendar?date=&view=&counselor_id=&service_id=&room_id=&status=` | `appointments.manage` | زمان‌بندی و فیلترها؛ timezone عملیاتی `Asia/Tehran`؛ E2E BLOCKED |
| رزرو/جست‌وجو `/booking/appointments` | `GET/POST /admin/booking/reservations`, `GET /admin/booking/reservations/{id}` | `appointments.manage` | فیلترهای `date,from,to,counselor_id,service_id,client_id,status,q,page`؛ E2E BLOCKED |
| لغو/جابجایی/وضعیت | `POST /admin/booking/reservations/{id}/cancel`, `PATCH .../{id}/reschedule`, `PATCH .../{id}/status` | `appointments.manage` | status transitions و ثبت دلیل/تاریخچه در سرور؛ backend lifecycle tests PASS |
| ورود و اتاق `/booking/reception`, `/booking/rooms` | `POST /admin/booking/reservations/{id}/check-in`, `GET/POST /admin/booking/rooms`, `PATCH /admin/booking/rooms/{id}`, `PATCH /admin/booking/reservations/{id}/room` | `appointments.manage` | تداخل اتاق رد می‌شود؛ Feature PASS، پنل E2E BLOCKED |
| صف انتظار `/booking/waitlist` | `GET/PATCH /admin/booking/waitlist` | `appointments.manage` | تخصیص entry رزرو held می‌سازد و notice صف می‌کند؛ Feature PASS |
| مراجع `/booking/clients` | `GET/POST /admin/booking/clients`; سابقه از `GET /admin/booking/reservations?client_id=` | `appointments.manage` | اطلاعات پایه و سابقه رزرو |
| پرونده و intake `/booking/clinical` | `GET/PUT /admin/booking/clinical/clients/{id}`, `GET .../{id}/intake-answers`, `GET .../{id}/consents` | `notes.manage` | دسترسی پذیرش/مالی رد می‌شود؛ Feature PASS |
| فرم‌ها `/booking/forms` و intake عمومی | `GET/POST /admin/booking/clinical/forms`, `POST /admin/booking/clinical/forms/{id}/versions`, `GET/POST /booking/intake-forms/{id}[ /answers ]`, `POST /client/consents` | `notes.manage` برای مدیریت؛ client برای submission | نسخه و اعتبارسنجی schema؛ Feature PASS |
| پیگیری و فایل | `GET/POST /admin/booking/clinical/clients/{id}/follow-ups`, `POST /admin/booking/clinical/follow-ups/{id}/complete`, `POST/GET .../{id}/attachments`, `GET /admin/booking/clinical/attachments/{id}` | `notes.manage` | فایل private disk؛ بررسی authorization در Feature PASS |
| یادداشت مشاور `/counselor` | `GET/POST /counselor/appointments/{id}/notes`, `PATCH /counselor/session-notes/{id}` | مشاور تخصیص‌یافته | draft/finalized؛ counselor دیگر 404 می‌گیرد؛ Feature PASS |
| جلسه/اتصال | `PATCH /admin/booking/reservations/{id}/session-details`, counselor equivalent; `GET /booking/my-reservations/{id}` | admin/counselor/client با scope | لینک Meet/شماره فقط در پنجره مجاز آزاد می‌شود؛ تست زمان‌بندی PASS |
| مالی `/booking/finance`, `/booking/refunds` | `GET /admin/booking/finance/dashboard`, `/payments`, `/refunds`, `/settlements`, `/reconciliation`; `POST /admin/booking/reservations/{id}/refunds`; `PATCH /admin/booking/refunds/{id}`; `POST /admin/booking/refunds/{id}/record-transfer`; `POST /admin/booking/settlements` | `finance.view` / `finance.manage` | Fake Bank؛ Featureهای ledger/refund/settlement PASS؛ browser E2E BLOCKED |
| تخفیف و گزارش | `GET/POST /admin/booking/promo-codes`, `PATCH .../{id}`; `GET /admin/booking/reports`, `/reports/export`, `/payments/export` | `finance.manage` / `finance.view` | CSV و capacity API؛ Feature PASS |
| اعلان `/booking/notifications` | `GET /admin/booking/notifications`, `GET/POST /admin/booking/notification-templates`, `POST /admin/booking/notifications/{id}/retry` | `appointments.manage` | retry/email queue و privacy اعلان؛ backend tests PASS؛ SMS واقعی غیرفعال |

## پاسخ خطا و وضعیت اجرای فعلی

- اعتبارسنجی ورودی: HTTP 422 با `message` و `errors` لاراول.
- نیازمند احراز هویت: 401 JSON؛ permission ناکافی: 403؛ resource خارج از scope مشاور غالباً 404؛ تعارض transition: 409 یا validation 422 مطابق endpoint.
- PHPUnit این نوبت: PASS (44 تست / 356 assertion، SQLite `:memory:`).
- MySQL و browser E2E: BLOCKED (Docker daemon/socket در دسترس نبود). هیچ write به `8010` اجرا نشد.
- فایل‌های گزارش هم‌نام در مخزن پنل خارج از writable root بودند؛ این ماتریس در مخزن backend نگهداری می‌شود.
