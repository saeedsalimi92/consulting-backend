# احراز هویت OTP و SMS.ir

## وضعیت

APIهای OTP، ایجاد حساب پس از تأیید، محدودیت ارسال، ثبت وضعیت delivery و adapter HTTP برای SMS.ir پیاده‌سازی شده‌اند. ارسال از `POST https://api.sms.ir/v1/send/verify` با هدر `x-api-key` و پارامترهای template انجام می‌شود. Sandbox و Production کلید مستقل دارند؛ Sandbox برای OTP از قالب `123456` استفاده می‌کند و نتیجه را شبیه‌سازی‌شده ثبت می‌کند. Production به‌صورت پیش‌فرض غیرفعال است.

## مسیرهای عمومی

مسیر تاریخی `POST /api/client/register` deprecated و حفظ‌شده برای سازگاری است؛ همیشه HTTP 410 با `OTP_REGISTRATION_REQUIRED` برمی‌گرداند و هیچ کاربری ایجاد نمی‌کند. تنها مسیر ساخت عمومی حساب، verify موفق OTP است. ورود password موجود از `POST /api/client/login` باقی می‌ماند.

`GET /api/auth/config` عمومی است و `data.recaptcha` را با `enabled`, `version` و `site_key` و نیز تنظیمات طول/اعتبار/cooldown OTP برمی‌گرداند.

`POST /api/auth/otp/request` و `POST /api/auth/otp/resend` عمومی‌اند. هر دو بدنهٔ زیر را می‌پذیرند؛ action برای اولی `otp_request` و برای دومی `otp_resend` است:

```json
{"mobile":"09123456789","recaptcha_token":"<v3-token>","recaptcha_action":"otp_request"}
```

پاسخ پذیرفته‌شده:

```json
{"success":true,"data":{"challenge_id":"<uuid>","expires_in":300,"resend_after":60,"code_length":6,"accepted_requests_remaining":2}}
```

پذیرش یعنی SMS provider ارسال را پذیرفته؛ درخواست OTP به‌تنهایی ورود یا وجود حساب را اثبات نمی‌کند. اگر SMS.ir تنظیم یا آماده نباشد HTTP 503 با کد `SMS_PROVIDER_UNAVAILABLE` برمی‌گردد و چالشی قابل تأیید ایجاد نمی‌شود.

`POST /api/auth/otp/verify`:

```json
{"mobile":"09123456789","challenge_id":"<uuid>","code":"123456"}
```

پاسخ ورود موفق شامل `success`, `token`, `data` کاربر، `has_password`, `profile_complete`, `missing_profile_fields`, `mobile_verified` است. password login هم همین capabilityها را در root response و user envelope می‌دهد. توکن همان Sanctum Personal Access Token فعلی است و در URL قرار نمی‌گیرد. ورود account creation را فقط پس از OTP درست و مصرف‌نشده انجام می‌دهد. رمز موجود از `POST /api/auth/password/set` با `PASSWORD_ALREADY_CONFIGURED` (409) محافظت می‌شود.

## قواعد OTP

- شماره‌های ایرانی به `+989xxxxxxxxx` canonical می‌شوند؛ SMS.ir مقصد بدون پیشوند `+98` می‌گیرد.
- OTP شش رقمی، پنج دقیقه‌ای و HMAC شده با `APP_KEY` است؛ plaintext در DB/log/پاسخ ذخیره نمی‌شود.
- پنج تلاش ناموفق، سپس قفل challenge. کد مصرف‌شده قابل replay نیست.
- حداکثر سه ارسال پذیرفته‌شده در ساعت شناور و یک ارسال در هر ۶۰ ثانیه برای شمارهٔ canonical. provider failure جزو سه پیام پذیرفته‌شده نیست.
- rate limit IP نیز روی routeها اعمال می‌شود. پاسخ محدودیت HTTP 429 و `Retry-After` دارد.
- شمارنده‌ها و challengeها در DB هستند؛ refresh، tab یا worker آن‌ها را reset نمی‌کند.

## reCAPTCHA

v3 سمت سرور با Google `siteverify` بررسی می‌شود. action باید دقیق باشد: `otp_request`, `otp_resend`, `mobile_change`, `auth_step_up`. در صورت فعال بودن CAPTCHA، نبود کلید/عدم دسترسی verifier در production به رد درخواست می‌انجامد. Client کلید عمومی را از `GET /api/auth/config` می‌گیرد؛ secret فقط در backend است. Google tokenها باید تازه باشند (حداکثر دو دقیقه). مستند رسمی: [v3](https://developers.google.com/recaptcha/docs/v3)، [تأیید سمت سرور](https://developers.google.com/recaptcha/docs/verify).

## SMS.ir

متغیرها: `SMS_PROVIDER=sms_ir`, `SMS_IR_MODE`, `SMS_IR_SANDBOX_API_KEY`, `SMS_IR_API_KEY`, `SMS_IR_BASE_URL` و `SMS_IR_LIVE_SEND_ENABLED` به‌علاوه شناسه قالب‌های booking، reminder، cancel و reschedule. در Sandbox نتیجه `sandbox_simulated` است و به‌عنوان تحویل واقعی ثبت نمی‌شود. جزئیات راه‌اندازی در [SMSIR-CONFIGURATION.md](SMSIR-CONFIGURATION.md).
