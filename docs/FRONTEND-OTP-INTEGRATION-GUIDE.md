# راهنمای قطعی اتصال Nuxt به احراز هویت

Base URL در محیط محلی فعلی `http://localhost:8010/api` است. API از Sanctum Bearer PAT استفاده می‌کند (نه session cookie). پس از login، token را طبق سیاست امن فعلی برنامه در حافظه/session storage نگه دارید، در هر درخواست به صورت `Authorization: Bearer <token>` بفرستید؛ هرگز در URL یا log قرار ندهید. هیچ تغییری در CSRF لازم نیست چون این مسیرهای API Bearer هستند. logout موجود `POST /api/client/logout` توکن فعلی را revoke می‌کند.

ساخت حساب عمومی فقط با OTP موفق انجام می‌شود. `POST /api/client/register` برای سازگاری باقی است، اما deprecated بوده و همیشه 410 `OTP_REGISTRATION_REQUIRED` می‌دهد؛ از آن استفاده نکنید. جزئیات stateها در [UNIFIED-MOBILE-LOGIN-FLOW.md](UNIFIED-MOBILE-LOGIN-FLOW.md) است. endpoint ناشناسی برای تشخیص account/password وجود ندارد؛ device preference فقط UX را انتخاب می‌کند.

## تشخیص قابلیت و CAPTCHA

`GET /api/auth/config` عمومی:

```json
{"data":{"recaptcha":{"enabled":true,"version":"v3","site_key":"PUBLIC_SITE_KEY"},"otp":{"code_length":6,"expires_in":300,"resend_after":60}}}
```

Google v3 را با site key عمومی اجرا کنید. actionها دقیقاً `otp_request`, `otp_resend`, `mobile_change`, `auth_step_up` هستند. برای هر attempt token تازه بگیرید. سرور action، hostname، score، success و freshness را بررسی می‌کند. در صورت unavailable شدن CAPTCHA، متن قابل retry نمایش دهید؛ اعتبارسنجی را bypass نکنید.

## OTP

### درخواست/ارسال مجدد

`POST /api/auth/otp/request` عمومی:

```json
{"mobile":"۰۹۱۲۳۴۵۶۷۸۹","recaptcha_token":"TOKEN","recaptcha_action":"otp_request"}
```

`POST /api/auth/otp/resend` همان payload با action برابر `otp_resend` می‌پذیرد. موفقیت:

```json
{"success":true,"data":{"challenge_id":"UUID","expires_in":300,"resend_after":60,"code_length":6,"accepted_requests_remaining":2}}
```

فقط پاسخ 2xx بالا به معنی پذیرفته شدن ارسال از provider است؛ به معنی login نیست. سه پیام پذیرفته‌شده در ۶۰ دقیقهٔ rolling و ۶۰ ثانیه cooldown enforce می‌شوند. `Retry-After` و `retry_after_seconds` در 429 مرجع نمایش زمان‌اند. refresh یا درخواست از tab دیگر محدودیت را reset نمی‌کند.

### تأیید

`POST /api/auth/otp/verify` عمومی:

```json
{"mobile":"09123456789","challenge_id":"UUID","code":"۱۲۳۴۵۶"}
```

موفقیت:

```json
{"success":true,"token":"1|SANCTUM_TOKEN","data":{"id":42,"name":"...","first_name":null,"last_name":null,"email":null,"phone":"+989123456789","phone_verified_at":"...","profile_complete":false,"missing_profile_fields":["first_name","last_name"],"mobile_verified":true,"has_password":false},"has_password":false,"profile_complete":false,"missing_profile_fields":["first_name","last_name"],"mobile_verified":true}
```

challenge پنج دقیقه اعتبار و پنج تلاش ناموفق دارد؛ پس از مصرف قابل replay نیست. شمارهٔ ناشناخته فقط پس از verification به client حداقلی تبدیل می‌شود. درخواست کد وجود/عدم وجود account را فاش نمی‌کند.

## رمز عبور

ورود password قبلی حفظ شده: `POST /api/client/login` با فیلد `email` که می‌تواند email یا شمارهٔ موبایل canonical‌پذیر باشد، و `password`. پاسخ token/user همان قرارداد قبلی است و user اکنون capabilityهای پروفایل را نیز دارد.

بعد از OTP اگر `has_password=false`، رمزگذاری اختیاری است و کاربر می‌تواند رد کند. `POST /api/auth/password/set` با Bearer auth و بدنهٔ `{"password":"...","password_confirmation":"..."}`. حداقل ۱۲ کاراکتر شامل حروف کوچک/بزرگ و عدد؛ OTP موفق باید در ده دقیقهٔ اخیر باشد. پاسخ `{"success":true,"data":{"has_password":true}}`. حسابی که password دارد `PASSWORD_ALREADY_CONFIGURED` (409) می‌گیرد و باید از جریان change/recovery موجود استفاده کند.

## پروفایل و ادامه رزرو

`GET /api/auth/me` با Bearer، وضعیت جاری را می‌دهد. `PUT /api/auth/profile` بدنهٔ حداقل `{"first_name":"...","last_name":"..."}` را می‌گیرد و همان profile envelope را برمی‌گرداند. تکمیل نام، شمارهٔ تأییدشده را جایگزین نمی‌کند.

تغییر شماره: authenticated `POST /api/auth/mobile/change/request` با `mobile`, token/action `mobile_change`; سپس `POST /api/auth/mobile/change/verify` با `mobile`, `challenge_id`, `code`. شمارهٔ اشغال‌شده پذیرفته نمی‌شود.

اگر `profile_complete=false`، booking را آغاز/تأیید نکنید؛ profile form را نمایش دهید و booking context انتخاب‌شده را در state نگه دارید. بعد از ذخیره، `/auth/me` را refresh، availability را دوباره بگیرید و رزرو را با قیمت و قواعد backend ادامه دهید. خطای `PROFILE_INCOMPLETE` حتی در صورت missed pre-check باید همان flow را باز کند. خطای slot conflict به انتخاب مجدد زمان نیاز دارد.

## خطاها

```json
{"success":false,"code":"OTP_RATE_LIMITED","message":"...","retry_after_seconds":120}
```

مقادیر مهم: `OTP_REGISTRATION_REQUIRED` (410), `MOBILE_INVALID` (422), `CAPTCHA_INVALID` (422), `CAPTCHA_UNAVAILABLE` (503), `OTP_INVALID` (422), `OTP_EXPIRED` (422), `OTP_CONSUMED` (422), `OTP_TOO_MANY_ATTEMPTS` (429), `OTP_COOLDOWN` (429), `OTP_RATE_LIMITED` (429), `SMS_PROVIDER_UNAVAILABLE` (503), `MOBILE_ALREADY_USED` (409), `PROFILE_INCOMPLETE` (422), `PASSWORD_ALREADY_CONFIGURED` (409). نمایش متن کوتاه فارسی؛ raw exceptions را نشان ندهید.

## وضعیت سرویس‌دهنده

HTTP adapter واقعی SMS.ir به دلیل نبود مستندات رسمی قابل تأیید هنوز تکمیل نشده و در حال حاضر fail-closed است؛ credential خالی نیز ارسال موفق نمی‌سازد. Google keys نیز باید در محیط مستقر تنظیم شوند. در این حالت UI خطای قابل retry برای ارسال نمایش می‌دهد و نباید کاربر را واردشده فرض کند.
