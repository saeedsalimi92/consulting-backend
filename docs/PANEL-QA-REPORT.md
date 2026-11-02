# گزارش QA یکپارچه‌سازی پنل و بک‌اند

آخرین به‌روزرسانی: 2026-10-13

## محدوده و ایمنی

- در این اجرا هیچ درخواست HTTP به `8010` ارسال نشد و هیچ تست نوشتنی به آن پورت نرفت.
- Compose اصلی، دیتابیس پیش‌فرض `consulting` را تنظیم می‌کند؛ چون دسترسی Docker socket رد شد، مقصد اتصال runtime کانتینر قابل تأیید نبود. این گزارش مقدار runtime را حدس نمی‌زند.
- تست‌های PHPUnit طبق `phpunit.xml` به SQLite `:memory:` متصل‌اند. هیچ داده‌ای به دیتابیس `consulting` نرفت.
- `compose.mysql-test.yaml` محیط مستقل MySQL را با دیتابیس `consulting_booking_test_20261012`، volume جدا و API روی `127.0.0.1:8011` تعریف می‌کند. Compose/Docker در این محیط اجرا نشد؛ بنابراین آماده‌بودن runtime آن اثبات نشده است.
- پنل `/home/saeed/admin-modernize` فقط خوانده شد و فایل‌های آن تغییر نکردند. این محدودیت از writable root محیط می‌آید؛ نسخهٔ قابل‌ویرایش این گزارش در مخزن backend ثبت شده است.

## وضعیت قابلیت‌ها

| قابلیت | وضعیت | شواهد / محدودیت |
|---|---|---|
| قرارداد reception، رزرو، تقویم و فیلترها | PASS (backend) / PARTIAL (پنل) | route/controller و فراخوانی‌های پنل بررسی منبعی شدند؛ E2E پنل با API واقعی این نوبت اجرا نشد. |
| اتاق و جلوگیری از تداخل | PASS (backend) / BLOCKED (پنل E2E) | `ReceptionClinicalApiTest` تعارض و فیلتر اتاق در تقویم را اجرا می‌کند؛ اتصال MySQL/مرورگر در دسترس نبود. |
| check-in و چرخه جلسه | PASS (backend) / BLOCKED (پنل E2E) | تست Feature چک‌این و تغییر به `in_progress` را بررسی می‌کند. |
| صف انتظار و انتساب | PASS (backend) / BLOCKED (پنل E2E) | سناریوی اعلان و ساخت رزرو انتساب‌یافته پاس شد. |
| پرونده، فرم و رضایت | PASS (backend) / BLOCKED (پنل E2E) | ارسال پاسخ نسخه‌دار، رضایت و منع دسترسی پذیرش به endpoint بالینی تست شد. |
| یادداشت مشاور و پیوست خصوصی | PASS (backend) / BLOCKED (پنل E2E) | draft، دسترسی مشاور دیگر و دانلود پیوست خصوصی تست شدند. |
| مالی | PASS (backend) / BLOCKED (پنل E2E) | پرداخت Fake Bank، رسید/ledger، refund، settlement، promo و گزارش در Featureها بررسی شدند؛ اتصال بانکی واقعی فعال نشد. |
| اعلان | PASS (backend) / BLOCKED (پنل E2E) | retry صف ایمیل و زمان آزادسازی لینک جلسه تست شدند؛ SMS واقعی فعال نشد. |
| تفکیک نقش و حریم بالینی | PASS (backend tests) / PARTIAL (پنل) | پذیرش clinical را نمی‌خواند؛ مشاور نامرتبط note/attachment را نمی‌خواند؛ E2E مرورگر برای نقش‌های پنل انجام نشد. |
| ورود/رزرو شناخته‌شده روی 8010 | OPEN / NOT TESTED | عمداً به 8010 دست نزدیم؛ رفع‌شده اعلام نمی‌شود. |
| MySQL integration، مرورگر و build پنل | BLOCKED | Docker socket در دسترس نبود و در این workspace مجاز به نوشتن/build در مخزن پنل نیستم. |

## اجرای تست

فرمان اجراشده:

```bash
./vendor/bin/phpunit --testdox
```

نتیجه: **PASS، 44 تست، 356 assertion** روی SQLite حافظه‌ای. این نتیجه اجرای موفق PHPUnit است و اجرای API روی MySQL یا پنل را ثابت نمی‌کند.

## اقدام بعدی برای E2E ایزوله

با دسترسی Docker، از ریشه backend اجرا شود:

```bash
docker compose -f compose.mysql-test.yaml --project-name consulting-mysql-test up -d --build
docker compose -f compose.mysql-test.yaml --project-name consulting-mysql-test exec laravel-test php artisan migrate --force
```

پیش از هر browser suite باید API روی `8011` پاسخ دهد و اتصال آن با نام `consulting_booking_test_20261012` تأیید شود. سپس E2E پنل را با `TEST_API_BASE=http://localhost:8011/api` اجرا کنید. از `migrate:fresh` روی اتصال نامشخص استفاده نکنید.

## COMPLETE / PARTIAL / BLOCKED

- **COMPLETE:** backend contract/features موجود برای سناریوهای این گزارش؛ آزمون‌های Feature/Unit ایزوله در این اجرا PASS شدند.
- **PARTIAL:** یکپارچه‌سازی پنل از روی source دارای مسیر و API است، اما تست مرورگری، TypeScript/Vue verification و build مجدد این اجرا انجام نشده است.
- **BLOCKED:** runtime MySQL تست و E2E پنل به علت عدم دسترسی Docker socket؛ تغییر فایل‌های پنل خارج از writable root.
- **OPEN:** اشکال شناخته‌شده‌ی ورود/رزرو روی `8010`، بدون تست واقعی.
