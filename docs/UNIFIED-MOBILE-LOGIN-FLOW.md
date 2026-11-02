# جریان یکپارچهٔ ورود با موبایل

## اصل امنیتی و رفتار پیشنهادی

ساخت حساب عمومی فقط در `POST /api/auth/otp/verify`، پس از تأیید OTP انجام می‌شود. route قدیمی `POST /api/client/register` برای سازگاری باقی مانده ولی deprecated است و همیشه HTTP 410 با `OTP_REGISTRATION_REQUIRED` برمی‌گرداند؛ هیچ حسابی نمی‌سازد. ساخت/مدیریت کاربر توسط مدیر از مسیرهای محافظت‌شدهٔ admin مستقل است.

هیچ API ناشناسی برای تشخیص وجود حساب یا رمز عبور وجود ندارد. مشخص کردن اینکه یک موبایل دلخواه متعلق به حساب passwordدار است، بدون افشای اطلاعات حساب ممکن نیست. بنابراین روی دستگاه ناشناس OTP روش پیش‌فرض باشد. پس از ورود موفق با رمز، فرانت‌اند می‌تواند فقط preference غیرحساس محلی «رمز برای این دستگاه قبلاً موفق بوده» را ثبت کند و در مراجعات بعدی صفحهٔ رمز را نشان دهد. این preference مجوز backend نیست. روی دستگاه ناشناس، اگر کاربر رمز را انتخاب کند، یک فرم عمومی رمز ارائه شود؛ «شماره یا رمز نادرست است» باید پاسخ یکسان برای موبایل ناشناخته و رمز اشتباه باشد. هر زمان کاربر باید بتواند به OTP تغییر مسیر دهد.

## قراردادهای مشترک

- API روی Sanctum Bearer PAT فعلی کار می‌کند. ورود token را در پاسخ می‌دهد؛ Bearer header را برای مسیرهای محافظت‌شده استفاده کنید. token در URL قرار نگیرد.
- ورودی mobile به شمارهٔ canonical ایرانی تبدیل می‌شود. OTP فقط بعد از verify حساب می‌سازد.
- پاسخ login با رمز و OTP دارای `token`, `data` کاربر، `has_password`, `mobile_verified`, `profile_complete`, `missing_profile_fields` است.
- خطاها JSON هستند: `{"success":false,"code":"...","message":"..."}`. هیچ پاسخ ناشناس `user_exists`, `is_registered` یا `has_password` بر اساس شماره ندارد.

## State machine

### `ENTER_MOBILE`

- **ورود:** شروع login، session ناشناس.
- **API:** بدون lookup؛ `GET /api/auth/config` فقط برای تنظیمات عمومی اختیاری است.
- **ورودی:** شمارهٔ موبایل.
- **ادامه:** دستگاه با preference معتبر محلی می‌تواند `PASSWORD` را باز کند. در غیر این صورت پیش‌فرض `OTP_REQUEST` است. کاربر می‌تواند همیشه روش را عوض کند.
- **خطا:** `MOBILE_INVALID` در 422 هنگام ارسال OTP یا فرمت نامعتبر فرم؛ هیچ account existence اطلاعاتی ندارد.

### `PASSWORD`

- **ورود:** انتخاب کاربر یا preference محلی دستگاه؛ preference قابل‌اعتماد backend نیست.
- **API:** `POST /api/client/login`، عمومی و throttle شده.
- **Request:** `{"email":"09123456789","password":"..."}`. نام `email` برای سازگاری تاریخی است و می‌تواند موبایل canonical‌پذیر یا email موجود باشد.
- **موفقیت:** `{"token":"<Sanctum PAT>","data":{"id":42,"name":"...","phone":"+989123456789",...},"has_password":true,"mobile_verified":false,"profile_complete":false,"missing_profile_fields":["first_name","last_name","mobile"]}`.
- **خطا:** HTTP 422 با خطای generic اعتبارنامه (همان حالت موبایل ناشناخته/رمز اشتباه)، یا throttle HTTP 429. حساب ساخته نمی‌شود.
- **گذار:** موفق → `AUTHENTICATED`; انتخاب «ورود با کد پیامکی» → `OTP_REQUEST` با token تازهٔ CAPTCHA.

### `OTP_REQUEST`

- **ورود:** روش ناشناس پیش‌فرض یا انتخاب OTP از PASSWORD.
- **API:** `POST /api/auth/otp/request` عمومی.
- **Request:** `{"mobile":"09123456789","recaptcha_token":"<fresh-v3-token>","recaptcha_action":"otp_request"}`. هر attempt token تازه و action دقیق لازم دارد.
- **موفقیت:** `{"success":true,"data":{"challenge_id":"<uuid>","expires_in":300,"resend_after":60,"code_length":6,"accepted_requests_remaining":2}}`.
- **خطا:** `MOBILE_INVALID` 422، `CAPTCHA_INVALID` 422، `CAPTCHA_UNAVAILABLE` 503، `OTP_COOLDOWN`/`OTP_RATE_LIMITED` 429 با `Retry-After` و `retry_after_seconds`، `SMS_PROVIDER_UNAVAILABLE` 503. شکل خطا حساب موجود/ناموجود را افشا نمی‌کند.
- **گذار:** پذیرش provider → `OTP_VERIFY`. این پذیرش به معنی authenticated بودن نیست.

### `OTP_VERIFY`

- **ورود:** challenge پیامکی پذیرفته‌شده.
- **API:** `POST /api/auth/otp/verify` عمومی.
- **Request:** `{"mobile":"09123456789","challenge_id":"<uuid>","code":"123456"}`؛ ارقام فارسی/عربی هم پذیرفته می‌شوند.
- **موفقیت:** token Sanctum واقعی، `data` user، و capabilityها. موبایل تأییدشده است؛ اگر حساب ناشناخته باشد، حساب حداقلی client در همین مرحله ساخته می‌شود. حساب مبهم/privileged با پیام عمومی رد می‌شود و duplicate ساخته نمی‌شود.
- **خطا:** `OTP_INVALID` 422، `OTP_EXPIRED` 422، `OTP_CONSUMED` 422، `OTP_TOO_MANY_ATTEMPTS` 429، CAPTCHA/SMS errors از درخواست.
- **گذار:** `AUTHENTICATED`؛ اگر `has_password=false` فرانت‌اند می‌تواند `OPTIONAL_PASSWORD_SETUP` را نشان دهد. اگر `profile_complete=false`، قبل از رزرو به `PROFILE_COMPLETION` بروید.

### `AUTHENTICATED`

- **ورود:** password login یا OTP verify موفق.
- **API:** مسیرهای خصوصی با `Authorization: Bearer <token>`؛ `GET /api/auth/me` وضعیت authoritative جاری را می‌دهد.
- **موفقیت `/auth/me`:** `{"data":{"id":42,...,"has_password":false,"mobile_verified":true,"profile_complete":false,"missing_profile_fields":["first_name","last_name"]}}`.
- **گذار:** بدون رمز → اختیاری `OPTIONAL_PASSWORD_SETUP`; رزرو با پروفایل ناقص → `PROFILE_COMPLETION`; در غیر این صورت جریان رزرو.

### `OPTIONAL_PASSWORD_SETUP`

- **ورود:** OTP login و `has_password=false`; این گام قابل skip است.
- **API:** `POST /api/auth/password/set`, authenticated.
- **Request:** `{"password":"StrongPassword123","password_confirmation":"StrongPassword123"}`. رمز حداقل ۱۲ کاراکتر، حروف کوچک/بزرگ و عدد؛ OTP موفق باید در ده دقیقهٔ اخیر باشد.
- **موفقیت:** `{"success":true,"data":{"has_password":true}}`. hash توسط Laravel ذخیره می‌شود و token جاری حفظ می‌شود.
- **خطا:** validation 422، احراز OTP ناکافی 403، `PASSWORD_ALREADY_CONFIGURED` 409. برای تغییر رمز موجود از `PUT /api/client/password` و برای recovery از routeهای reset موجود استفاده شود.
- **گذار:** submit یا «بعداً» → `PROFILE_COMPLETION` اگر ناقص باشد، وگرنه `BOOKING_RESUME`/صفحهٔ مقصد قبلی.

### `PROFILE_COMPLETION`

- **ورود:** `profile_complete=false` یا پاسخ رزرو `PROFILE_INCOMPLETE`.
- **API:** `PUT /api/auth/profile` با `{"first_name":"...","last_name":"..."}`؛ شمارهٔ OTP تأییدشده read-only است. تغییر شماره مسیر step-up/OTP جدا دارد.
- **موفقیت:** profile envelope به‌روز. سپس `GET /api/auth/me` برای recheck.
- **خطا:** اعتبارسنجی 422؛ شمارهٔ legacy بدون `phone_verified_at` همچنان ناقص است.
- **گذار:** کامل شد → `BOOKING_RESUME` با حفظ selections.

### `BOOKING_RESUME`

- **ورود:** پروفایل تکمیل و احراز هویت حفظ شده.
- **API:** جریان رزرو موجود. backend هنگام ساخت رزرو و تأیید نهایی، پروفایل را دوباره کنترل می‌کند.
- **موفقیت:** رزرو طبق قراردادهای موجود؛ availability و قیمت دوباره authoritative هستند.
- **خطا:** `PROFILE_INCOMPLETE` 422 → `PROFILE_COMPLETION`; conflict/slot unavailable → انتخاب زمان مجدد. مجوز frontend جای کنترل backend نیست.

## CAPTCHA actionها

OTP actionهای این سند: `otp_request` و `otp_resend`. سایت‌کلید عمومی از `GET /api/auth/config` خوانده می‌شود؛ secret فقط server-side است. token باید برای هر تلاش تازه باشد. Provider پیامک SMS.ir تا تکمیل HTTP adapter رسمی fail-closed است.
