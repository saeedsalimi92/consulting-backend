# تکمیل پروفایل پیش از رزرو

الزام authoritative شامل `first_name`, `last_name`, `phone_verified_at` است. پاسخ auth/profile دارای `profile_complete`, `missing_profile_fields`, `mobile_verified`, `has_password` است. شمارهٔ legacy فقط به دلیل وجود مقدار `phone` تأییدشده تلقی نمی‌شود.

مراجع OTP تازه‌وارد ابتدا بدون نام و رمز ساخته می‌شود. فرانت نام/نام خانوادگی را با `PUT /api/auth/profile` ثبت می‌کند؛ شمارهٔ تأییدشدهٔ OTP از پروفایل خواندنی است. تغییر موبایل از مسیرهای authenticated `POST /api/auth/mobile/change/request` و `/verify` انجام می‌شود و شمارهٔ جدید تا OTP موفق جایگزین شمارهٔ تأییدشده نمی‌شود.

رزرو مستقیم client با پروفایل ناقص HTTP 422 و کد `PROFILE_INCOMPLETE` می‌گیرد. کنترل هنگام آغاز رزرو و هنگام تأیید پرداخت برای booking client انجام می‌شود. مسیرهای کارمند مجاز مستقل باقی می‌مانند. پس از تکمیل پروفایل، کلاینت باید `GET /api/auth/me` را تازه کند و booking context قبلی را نگه دارد؛ availability در زمان submit دوباره بررسی می‌شود. اگر بازه گرفته شده باشد API conflict عادی booking را می‌دهد و زمان باید انتخاب شود.

ساخت account یا session سمت client نمی‌تواند این قاعده را دور بزند؛ کنترل backend مرجع نهایی است.
