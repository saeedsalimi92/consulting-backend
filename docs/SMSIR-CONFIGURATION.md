# پیکربندی SMS.ir

## اتصال Sandbox و Production

Backend از abstraction مشترک `SmsProvider` و HTTP client لاراول استفاده می‌کند. مسیر Verify مطابق قرارداد رسمی `POST https://api.sms.ir/v1/send/verify` است. پاسخ فقط زمانی پذیرفته می‌شود که HTTP موفق و مقدار JSON `status` برابر ۱ باشد؛ HTTP 200 به‌تنهایی موفقیت نیست.

Sandbox و Production هر دو API URL یکسان دارند، اما کلیدها کاملاً جدا هستند. حالت پیش‌فرض `sandbox` است؛ کلید Production هرگز جایگزین کلید Sandbox نمی‌شود. ارسال Production فقط با `SMS_IR_MODE=production`، کلید Production و `SMS_IR_LIVE_SEND_ENABLED=true` فعال می‌شود. برای OTP در Sandbox، قالب ثابت Verify برابر `123456` و پارامتر `Code` است. پاسخ Sandbox صرفاً شبیه‌سازی پذیرش است و نشان‌دهنده تحویل به گوشی نیست.

## متغیرهای محیط

```dotenv
SMS_PROVIDER=sms_ir
SMS_IR_MODE=sandbox
SMS_IR_SANDBOX_API_KEY=
SMS_IR_API_KEY=
SMS_IR_BASE_URL=https://api.sms.ir/v1
SMS_IR_LIVE_SEND_ENABLED=false
SMS_IR_OTP_TEMPLATE_ID=
SMS_IR_BOOKING_TEMPLATE_ID=
SMS_IR_REMINDER_TEMPLATE_ID=
SMS_IR_CANCEL_TEMPLATE_ID=
SMS_IR_RESCHEDULE_TEMPLATE_ID=
```

کلیدها فقط در secret store/environment سرور قرار بگیرند. هرگز در API تنظیمات عمومی، OpenAPI، log یا frontend منتشر نشوند. ارسال Production به‌طور پیش‌فرض غیرفعال است و هیچ fallback بین credentialها وجود ندارد.

## قالب‌ها و رویدادها

برای Production، قالب‌ها باید در پنل SMS.ir ساخته و متغیرهایشان تأیید شوند. OTP فقط کد را می‌فرستد؛ booking فقط بعد از پرداخت تأییدشده یا تأیید رزرو رایگان؛ reminder طبق ruleهای موجود؛ cancellation/reschedule پس از transition معتبر. محتوای پیام نباید حاوی دادهٔ حساس درمانی باشد. Sandbox نتیجه را با وضعیت داخلی `sandbox_simulated` ثبت می‌کند؛ jobهای booking و reminder آن را تحویل‌شده به گوشی علامت نمی‌زنند.

## صف، زمان‌بندی و پایش

Reminderهای فعلی scheduler، queue، timezone تهران، consent، quiet hours و delivery log خود را حفظ می‌کنند. worker و scheduler فعلی پروژه را روشن نگه دارید. Provider درخواست‌ها را خودکار تکرار نمی‌کند؛ timeout مبهم نیز خطا است تا از ارسال OTP تکراری جلوگیری شود. خطاهای provider باید alert شوند؛ phone، OTP، API key و محتوای خصوصی پیام در log ثبت نشوند.

## Rollback

با تغییر `SMS_PROVIDER` به driver قبلی و بازگرداندن config، ارسال جدید به provider قبلی برمی‌گردد. رکوردهای delivery برای audit باقی می‌مانند. این پیاده‌سازی migration جدیدی ندارد.
