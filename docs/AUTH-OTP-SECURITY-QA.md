# بررسی امنیت و آزمون احراز هویت OTP

## کنترل‌های پیاده‌شده

- canonical کردن ارقام فارسی/عربی و فرمت‌های `09`, `98`, `+98` پیش از lookup و محدودسازی.
- challenge با شناسهٔ opaque، hash کلیددار، زمان انقضا، شمارش تلاش و مصرف اتمیک.
- lock دیتابیسی برای محدودیت per-mobile و درخواست همزمان؛ سقف ۳ ارسال پذیرفته‌شده در rolling hour و cooldown ۶۰ ثانیه.
- محدودیت IP برای درخواست و تأیید OTP.
- هیچ account-existence endpoint وجود ندارد؛ پیام درخواست برای شمارهٔ موجود/جدید یکسان است.
- ساخت client فقط پس از تأیید موفق. نقش‌های privileged از OTP به client تبدیل یا duplicate نمی‌شوند.
- CAPTCHA v3 سمت backend؛ secret در configuration سرور باقی می‌ماند.
- passwords با Hash facade ذخیره می‌شوند؛ session/token به localStorage یا URL از طرف API نمی‌رود.
- booking gate از سرویس پروفایل مرکزی و فیلد زمان تأیید موبایل استفاده می‌کند.

## آزمون خودکار

`DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/phpunit --filter=OtpAuthenticationTest` برای سناریوهای OTP، CAPTCHA، ورود، پروفایل و booking gate استفاده می‌شود. اجرای کامل suite روی همین SQLite حافظه‌ای انجام شده است؛ این اجرا، MySQL integration محسوب نمی‌شود. تست live گوگل یا SMS.ir بدون credential انجام‌پذیر نیست.

## محدودیت‌های باقیمانده

- برای ارسال live، مستند رسمی SMS.ir، کلید API و templateهای تأییدشده نیاز است. Provider در وضعیت فعلی fail-closed است.
- Google site/secret key و hostname مجاز برای اجرای live نیاز است.
- مهاجرت‌ها هنوز روی هیچ دیتابیسی اجرا نشده‌اند. پیش از deploy باید collision شماره‌های canonical و شرایط nullable شدن email/password در backup/isolated clone بررسی شوند.
