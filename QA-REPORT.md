# گزارش QA یکپارچه رزرو و پنل‌ها

تاریخ اجرا: ۱۴۰۵/۰۷/۲۰ (2026-10-12)

## جمع‌بندی اجرا

| بخش | آزمون‌ها | موفق | ناموفق | مسدود/اجرا‌نشده در همین بخش |
|---|---:|---:|---:|---:|
| تست‌های Feature/Unit لاراول روی SQLite حافظه‌ای | 31 | 31 | 0 | 0 |
| سایت عمومی: Playwright، دسکتاپ و موبایل | 23 | 23 | 0 | 0 |
| مدیریت و مشاور: Playwright و API واقعی | 6 | 6 | 0 | 0 |
| **مجموع آزمون‌های نهایی** | **60** | **60** | **0** | **0** |

تست‌های PHPUnit در مجموع **264 assertion** داشتند. assertionهای Playwright به‌شکل جداگانه شمرده نشده‌اند. این گزارش نتیجه‌ی دور نهایی است؛ چند اجرای میانی هنگام restart هم‌زمان Nuxt ناپایدار بود یا selector آزمون نادرست بود؛ بعد از اصلاح/راه‌اندازی مجدد، تمام سناریوهای بالا دوباره اجرا شدند.

## دیتابیس و ایمنی

- آزمون‌های Laravel از SQLite `:memory:` طبق `phpunit.xml` استفاده کردند.
- سناریوهای مرورگر/HTTP روی MySQL جداگانه‌ی `consulting_booking_test_20261012` و API آزمایشی `http://localhost:8011/api` اجرا شدند. پیش از migration/reset نام دیتابیس از خود اتصال Laravel خوانده و با نام دقیق بالا تطبیق داده شد؛ `migrate:fresh --seed --seeder=DemoSeeder` فقط روی همین دیتابیس disposable انجام شد.
- API اصلی `http://localhost:8010/api` فقط برای health، چند endpoint عمومی و CORS به‌شکل read-only بررسی شد. رزرو مرورگری روی آن انجام نشد تا داده‌ی `consulting` دست‌نخورده بماند.
- **یادداشت ایمنی:** در اجرای اولیه، فرایند HTTP آزمایشی متغیر اتصال را منتقل نکرد و دو حساب یکتای E2E به‌طور موقت در `consulting` ساخته شدند. آن دو حساب و tokenهای مربوط به آن‌ها در transaction حذف شدند؛ هیچ‌کدام رزرو نداشتند. پس از پاک‌سازی شمار کاربران به مقدار قبل برگشت و بررسی انجام شد. تمام اجراهای بعدی به دیتابیس disposable محدود شدند. بنابراین دیتابیس اصلی در پایان پاک/بازنشانی نشده و داده‌های demo تغییر داده نشدند، اما این دو درج موقت باید در سابقه‌ی کار ثبت بماند.

## مرحله ۱ و ۲ — سایت عمومی

نشانی‌ها هنگام QA: Nuxt `http://localhost:3000`، backend اصلی `http://localhost:8010`, و backend جداشده‌ی تست `http://localhost:8011`.

**نتایج PASS (۲۳):** صفحه‌ی خانه، فهرست/جزئیات مشاور، خدمات، درباره، تماس، مقاله‌ها، FAQ، گالری، رزرو و ورود؛ پاسخ API واقعی مشاور/خدمت/محتوا؛ RTL و نبود overflow در 768 و 390 پیکسل؛ ثبت‌نام، ماندگاری نشست بعد reload، خروج، ورود مجدد و پروفایل؛ رزرو واقعی از مسیر UI، پرداخت ناموفق Fake Bank، حفظ رزرو در حالت `held` و مبلغ پرداخت‌شده صفر، retry، پرداخت موفق، تأیید رزرو، یک پرداخت verified، یک رسید، نوبت/پرداخت/رسید/اعلان حساب، تغییر زمان با UI و API و ثبت درخواست لغو؛ بدون خطای JavaScript مرورگر.

### رزرو پرداخت‌شده‌ی تطبیق‌شده

- شناسه: **184**؛ وضعیت backend: `confirmed`؛ مبلغ پرداخت‌شده: **19,000,000 ریال** (۱,۹۰۰,۰۰۰ تومان).
- پرداخت موفق از provider `fake_bank` ثبت شد؛ تلاش ناموفق قبلی نیز در سابقه ماند.
- رسید پایدار: `RC-20261012-00000180`.
- دفتر کل همان رزرو: نقد `debit` برابر 19,000,000؛ سهم مرکز `credit` برابر 15,200,000؛ payable مشاور `credit` برابر 3,800,000؛ اختلاف تراز صفر.
- آزمون retry یک پرداخت verified و یک receipt برای این رزرو مشاهده کرد؛ در UI هم نوبت و رسید نمایش داده شدند.

### تغییرات سایت عمومی و اشکالات رفع‌شده

- API واقعی `PATCH /api/booking/my-reservations/{id}/reschedule` به composable متصل شد و فرم انتخاب تاریخ/ظرفیت موجود در جزئیات نوبت افزوده شد. متن منسوخِ «تغییر زمان پشتیبانی نمی‌شود» حذف شد.
- شناسه رزرو در کارت نوبت‌های حساب مراجع نمایش داده می‌شود.
- در آزمون، وضعیت failed از API بررسی شد؛ منتظر ماندن UI تا بارگذاری جزئیات، flaky check را برطرف کرد. برابری زمان reschedule به‌صورت instant بررسی می‌شود تا تفاوت نمایش timezone باعث false negative نشود.
- مستندات فرانت `README.md` و `API-GAPS.md` درباره‌ی endpoint تغییر زمان تصحیح شدند.

## مرحله ۳ — مدیریت، پذیرش و مشاور

نشانی پنل: `http://localhost:3001`؛ login مدیر از `/auth/login` و ورود مشاور از `/counselor/login`.

**نتایج PASS (۶ سناریو):** رد login نامعتبر؛ ورود مدیر و داشبورد واقعی؛ جست‌وجوی سراسری رزرو 184 از پنل، داده‌ی مالی و تقویم backend و مشاهده‌ی همان ID در جدول و تقویم؛ ورود مشاور و مشاهده‌ی همان ID/مراجع/خدمت/زمان، ثبت و بازیابی یادداشت خصوصی، مرور درآمد/مانده؛ رد دسترسی مشاور دوم به notes رزرو اول با 404؛ خروج مدیریت و مشاور و لغو token سروری.

فضای کاری مشاور داخل همان مخزن Nuxt افزوده شد: نوبت‌های scope‌شده، جزئیات/اطلاعات جلسه، یادداشت خصوصی، ثبت برگزاری یا عدم حضور، availability/time-off، درآمد و سوابق تسویه و اعلان‌ها. API جدید `POST /api/counselor/logout` توکن جاری را revoke می‌کند.

### اشکال رفع‌شده در پنل پذیرش

جدول رزروها، با وجود پشتیبانی API از query `q`, فقط صفحه‌ی جاری را محلی جست‌وجو می‌کرد؛ یک رزرو جدید ممکن بود به‌علت مرتب‌سازی زمان در صفحه‌ی جاری نباشد. درخواست رزروها اکنون `q` را به backend می‌فرستد و متن راهنما اصلاح شده است. شناسه رزرو در رویداد تقویم نیز نمایش داده می‌شود.

## Build و مرورگر

- Public Nuxt `npm run build`: **موفق**. هشدار باقی‌مانده: Node 20.20.2 پایین‌تر از نسخه‌ی پشتیبانی‌شده‌ی Nuxt 4.6.1 است؛ PostCSS نتوانست cssnano را resolve کند. build با همین warnings کامل شد.
- Admin Nuxt `npm run build`: **موفق**. هشدارهای باقی‌مانده: Browserslist قدیمی و chunk سمت کاربر بزرگ‌تر از 500 KB.
- Playwright public screenshots: `storage/qa/public/`؛ از جمله `desktop-homepage.png`, `desktop-counselor-listing.png`, `desktop-booking.png`, `desktop-client-account.png`, `desktop-confirmed-booking.png`, `mobile-768.png`, `mobile-390.png`.
- Playwright admin screenshots: `storage/qa/admin/`؛ `admin-dashboard.png`, `admin-bookings.png`, `admin-calendar-same-booking.png`, `counselor-portal.png`.
- در این محیط Playwright/Chromium از قبل نصب نبود؛ وابستگی Playwright و Chrome در `/tmp` آماده شد و به dependency هیچ برنامه‌ای افزوده نشد.

## مواردی که اجرا یا اثبات نشدند

- هیچ مرورگر/HTTP رزرو یا پرداختی روی `localhost:8010` و دیتابیس `consulting` اجرا نشد؛ اتصال واقعی write به backend فقط به endpoint آزمایشی 8011 و DB جداگانه وصل بود. تنظیم پیش‌فرض فرانت عمومی همچنان `http://localhost:8010/api` است.
- MySQL آزمون‌های هم‌زمان چند process برای race booking/payment اجرا نشد؛ قفل/تراکنش از مسیر آزمون API معمولی exercised شد اما contention واقعی تست نشد.
- SMS واقعی و Zarinpal عمداً فعال نشدند. ارسال پیامک و تحویل provider خارجی در مرورگر بررسی نشد.
- زمان‌بندی واقعی scheduler/queue در مرورگر منتظر گذشت زمان نگه‌داشته نشد؛ آزمون‌های کنترل‌شده‌ی زمان اعلان‌ها در 31 تست Laravel پوشش داده شدند.
- در پنل برای هر دو نقش reception و counselor، سناریوهای مجوز CRUD کامل انجام نشد؛ super-admin و scope محدودکننده‌ی counselor دوم بررسی شد. مرور موبایل پنل، رزرو دستی پذیرش، تغییرات مالی/refund/settlement از UI و رابط/گزارش role سفارشی reception در این دور تست نشده‌اند.
- اتصال session خارجی Meet/voice یک تماس زنده اجرا نمی‌کند؛ این فاز فقط ذخیره/نمایش امن مقادیر را دارد. این تست مرورگر مقدار منتشرشده‌ی secret session را پیش/پس از زمان release بررسی نکرد.
- هیچ موفقیت E2E در مرورگر برای محیط production اعلام نمی‌شود. سناریوها روی داده‌ی واقعی HTTP و DB ایزوله‌ی local اجرا شدند.

## فایل‌های تغییرکرده

Backend: `routes/api.php`, `tests/Feature/CounselorLogoutTest.php`, `tests/e2e_public.cjs`, `tests/e2e_admin.cjs`, `QA-REPORT.md`.

Public: `app/composables/useBookingApi.ts`, `app/pages/account/appointments/[id].vue`, `app/pages/account/appointments/index.vue`, `README.md`, `API-GAPS.md`.

Admin: `composables/useCounselorAuth.ts`, `middleware/admin.global.ts`, `pages/counselor/login.vue`, `pages/counselor/index.vue`, `components/BookingWorkspace.vue`.

## فرمان‌های تکرار آزمون‌ها

SQLite / PHPUnit:

```bash
cd /home/saeed/project/consulting-backend/consulting-backend
vendor/bin/phpunit
```

Public browser suite (نیازمند Nuxt روی 3000 و backend آزمایشی 8011 متصل به `consulting_booking_test_20261012`):

```bash
LD_LIBRARY_PATH=/tmp/elham-browser-libs/usr/lib/x86_64-linux-gnu \
PLAYWRIGHT_MODULE=/tmp/elham-public-e2e/node_modules/playwright \
CHROME_EXECUTABLE=/tmp/elham-chrome/opt/google/chrome/chrome \
PUBLIC_BASE_URL=http://localhost:3000 TEST_API_BASE=http://localhost:8011/api \
node tests/e2e_public.cjs
```

Admin/counselor browser suite (همان backend آزمایشی، پنل روی 3001 و fixture رزرو شماره 184):

```bash
LD_LIBRARY_PATH=/tmp/elham-browser-libs/usr/lib/x86_64-linux-gnu \
PLAYWRIGHT_MODULE=/tmp/elham-public-e2e/node_modules/playwright \
CHROME_EXECUTABLE=/tmp/elham-chrome/opt/google/chrome/chrome \
ADMIN_BASE_URL=http://localhost:3001 TEST_API_BASE=http://localhost:8011/api \
QA_BOOKING_ID=184 node tests/e2e_admin.cjs
```

## گزارش پیگیری محیط واقعی — 2026-10-13

### وضعیت اولویت‌ها

| اولویت/سناریو | وضعیت | شواهد و محدودیت |
|---|---|---|
| P1 — تشخیص درخواست رزرو با payload گزارش‌شده | **PASS (علت schema رفع شد) / NOT TESTED (POST واقعی)** | migration عقب‌مانده‌ی رزرو عامل QueryException روی جدول tax rules بود و اعمال شد. service 1 برای counselor 13 موجود است، pricing snapshot برابر 19,000,000 ریال است و slot `2026-10-14T15:00:00+03:30` آزاد است. POST رزرو روی 8010 انجام نشد؛ status درخواست قبلی دارای توکن نیز ثبت نشده است. |
| P1 — احراز نقش client | **PASS (isolated)** | تست regression: client مجاز رزرو می‌کند؛ admin و counselor رد می‌شوند؛ بدون توکن 401 است؛ نقش نادرست JSON 403 بدون trace می‌گیرد. تأیید client واقعی روی 8010 **NOT TESTED** است. |
| P1 — middleware و route | **PASS (source inspection)** | رزرو `POST /api/booking/reservations` پشت `auth:sanctum` و `EnsureClient` است. فایل فعلی در [EnsureClient.php](app/Http/Middleware/EnsureClient.php) از ability توکن `client` و `canAccessClientPortal()` استفاده می‌کند؛ `abort_unless` و stack trace گزارش‌شده در نسخه‌ی محلی حاضر نیستند. اختلاف stack با نسخه‌ی جاری هنوز توضیح قطعی ندارد. |
| P2 — login/profile/reload/logout واقعی 8010 | **NOT TESTED** | CORS preflight برای public origin پاسخ 204؛ `/api/client/me` بدون Bearer برابر 401 JSON؛ runtime از MySQL `consulting` استفاده می‌کند و config cache خاموش است. 50 حساب با مدل فعلی client-eligible هستند. login با credentials واقعی و profile/reload/logout آن نشست اجرا نشد. |
| P2 — auth واقعی در test DB | **PASS** | ثبت‌نام، login، دریافت پروفایل، ماندگاری پس از reload و logout در Playwright؛ admin و counselor login/logout نیز در suite پنل موفق شدند. |
| P3 — public integration | **PASS** | Playwright روی public Nuxt و API 8011: 23/23. شامل directory/details، auth، booking، pending payment، fake-bank failure/success/retry، history، receipt، cancellation، reschedule و notifications. |
| P4 — admin/reception/counselor integration | **PASS (isolated)** | Playwright پنل: 6/6؛ رزرو #184، مالی، تقویم، یادداشت خصوصی، scope مشاور و logout. پنل با API 8011 آزمایش شد؛ برای origin آزمایشی 3002 مرورگر CORS را غیرفعال کرد، بنابراین این اجرا آزمون CORS پنل production نیست. |
| P5 — lifecycle/MySQL concurrency | **PASS (isolated)** | مجموعه‌ی PHPUnit چرخه‌ی hold، پرداخت، لغو/بازپرداخت، reminder و release را پوشش داد. تست هم‌زمانی MySQL شش بررسی را گذراند: فقط یک رزرو از دو درخواست هم‌زمان، callback idempotent و ledger/receipt یکتا. |
| P6 — finance/security | **PASS (automated isolated)** | آزمون‌های ledger، سهم مرکز/مشاور، مالیات، تسویه، refund، replay و IDOR/private notes در PHPUnit و Playwright pass شدند. اتصال Zarinpal و SMS واقعی فعال یا آزمایش نشدند. |
| P7 — build/release | **PARTIAL** | build عمومی و پنل موفق‌اند. محیط API فعلی `APP_ENV=local`, `APP_DEBUG=true`, `QUEUE_CONNECTION=sync` است؛ scheduler فعال است. Production deployment، backup/restore، صف واقعی، logging و تنظیمات secrets در production **NOT TESTED** هستند؛ با این config وضعیت production-ready اعلام نمی‌شود. |

### علت خطای بازتولیدشده و تغییرات

- درخواست read-only بدون `Accept` به `/api/client/me` ابتدا **500 HTML** می‌داد: middleware احراز هویت برای redirect به route وب `login` ناموجود تلاش می‌کرد. پس از اصلاح، همان درخواست به **401 JSON** تبدیل شد. این بازتولید، علت خطای POST رزرو کاربر را ثابت نمی‌کند؛ frontend فعلی `Accept: application/json` می‌فرستد.
- در [bootstrap/app.php](bootstrap/app.php) درخواست‌های guest به API دیگر به route وب redirect نمی‌شوند و API exceptionها همیشه JSON می‌گیرند. regression test جدید در [ClientAuthAndNotificationsTest.php](tests/Feature/ClientAuthAndNotificationsTest.php) این پاسخ را ثابت می‌کند.
- تست MySQL در [booking_mysql_concurrency.php](tests/booking_mysql_concurrency.php) پاسخ رزرو را از ساختار واقعی API می‌خواند (`data.id`)؛ با اصلاح این bug، تمام مراحل رقابت booking/payment اجرا شدند.
- اجرای نهایی PHPUnit: **35 تست، 301 assertion، همگی PASS**.
- `npm run build` عمومی و پنل هر دو PASS شدند. هشدارهای باقی‌مانده: Node 20 پایین‌تر از نسخه‌ی پشتیبانی‌شده‌ی Nuxt عمومی، resolve نشدن cssnano، داده‌ی Browserslist قدیمی پنل و chunk پنل حدود 4.65 MB.

### ایمنی دیتابیس و درخواست‌های واقعی

- هیچ booking، user یا token جدیدی در دیتابیس `consulting` ایجاد نشد؛ شمار رزروهای main هنگام بررسی **180** بود و حساب‌های QA مورد استفاده در suite پنل در آن DB وجود ندارند. تست‌های تغییردهنده فقط روی `consulting_booking_test_20261012` انجام شدند؛ این DB reset یا seed نشد.
- در یک اجرای اولیه‌ی پنل، runtime config پنل آزمایشی به‌جای DB تست به API 8010 اشاره کرد. فقط loginهای QA ناموفق ارسال شدند؛ هیچ حساب QA یا توکن تازه‌ای در `consulting` وجود ندارد. با `CACHE_STORE=file` این تلاش‌ها فقط شمارنده‌ی rate-limit محلی را لمس کردند. سرویس‌های آزمایشی 8012/8013 متوقف شدند و اجرای موفق پنل روی API 8011 انجام شد.
- برای POST رزرو 8010، login با حساب واقعی و بررسی هویت/role توکن هیچ write یا token آزمایشی اجرا نشد. برای تکمیل P1/P2 لازم است status و JSON response همان درخواست و وجود/نبود `Authorization: Bearer` (بدون خود توکن)، به‌علاوه‌ی نتیجه‌ی login واقعی، بررسی شود.

### اصلاح فوری محیط واقعی — 2026-10-13

- **علت قطعی قابل‌بازتولید:** migration `2026_10_12_000003_extend_booking_lifecycle` روی دیتابیس `consulting` Pending بود. لاگ Laravel خطای MySQL `SQLSTATE[42S02]` برای جدول `consulting.booking_service_tax_rules` را در `app/Services/BookingPricing.php:10` ثبت کرده بود؛ مسیر رزرو پیش از آن از شرط نقش در `app/Http/Middleware/EnsureClient.php:8` عبور می‌کند، بنابراین حضور نام middleware در stack به‌تنهایی آن را علت خطا نمی‌کند. با این حال، متن/stack و status پاسخ همان POST قدیمی با توکن واقعی در دسترس نیست؛ status دقیق آن درخواست **NOT CAPTURED** است (QueryException در این مسیر معمولاً به 500 تبدیل می‌شود، اما آن را به‌عنوان status مشاهده‌شده گزارش نمی‌کنیم).
- **اقدام:** پس از اجازه‌ی صریح کاربر، فقط migration رو به جلو با `php artisan migrate --force` اجرا شد؛ status اکنون `[10] Ran` است. `up()` ستون‌ها/جدول‌های قابلیت رزرو را ایجاد و پنج policy default و دو gateway default درج می‌کند؛ هیچ reset، seeder یا rollback اجرا نشد. درگاه `fake_bank` در sandbox فعال و `zarinpal` غیرفعال ماند.
- **اثر روی `consulting`:** بله، schema تغییر کرد و تنظیمات پیش‌فرض بالا درج شدند. شمار نهایی booking/user/token همان 180/51/7 بود؛ هیچ کاربر، token، رزرو، پرداخت یا داده‌ی demo از طرف این بررسی ساخته/حذف نشد. این migration اجرای بخش‌های رزرو و scheduler را که به جدول‌های تازه نیاز دارند ممکن می‌کند.
- **اعتبارسنجی پس از migration:** `migrate:status` همه migrationها را Ran نشان داد؛ جدول tax rules / waitlist / receipts موجود است؛ `booking:policy_settings` پنج ردیف و gatewayها دو ردیف دارند. `GET /api/health` پاسخ 200؛ سرویس مشاور 13 شامل service 1 با قیمت 19,000,000 ریال و بیعانه 6,000,000 ریال؛ `GET /api/booking/availability?counselor_id=13&service_id=1&date=2026-10-14` پاسخ 200 و timezone برابر `Asia/Tehran` داد و slot `15:00+03:30` را available گزارش کرد. هیچ POST رزرو روی 8010 اجرا نشد.
- **قیمت‌گذاری، CORS و scheduler:** `BookingPricing::snapshot` روی داده‌ی service 1 بدون write با موفقیت اجرا شد: کل و مبلغ full برابر 19,000,000 ریال، مالیات صفر، commission برابر 3,800,000 و سهم مرکز 15,200,000. CORS preflight روی origin عمومی `http://localhost:3000` پاسخ 204 با اجازه‌ی `authorization,content-type,accept` داد. پس از مهاجرت، لاگ scheduler اجرای موفق `booking:maintain` در دقیقه‌های 19:13 تا 19:17 را ثبت کرد؛ خطاهای missing-table تا 19:12 ادامه داشتند.
- **HTTP و authorization:** route زنده‌ی `POST /api/booking/reservations` همچنان پشت `auth:sanctum`، `EnsureClient` و throttle است. regression suite بعد از تغییر: client معتبر مجاز، admin/counselor رد، guest برابر 401 و role نادرست برابر JSON 403؛ کل PHPUnit برابر 35 تست و 301 assertion PASS. endpoint guest در تست قبلی حتی بدون `Accept` نیز 401 JSON می‌دهد. این‌ها روی suite ایزوله‌اند و مجوز client واقعی روی `consulting` را ثابت نمی‌کنند.
- **Login:** login/profile/reload/logout در Playwright روی دیتابیس آزمایشی PASS است. کد frontend قرارداد `{data, token}` را مصرف می‌کند و token را در `sessionStorage` با کلید `client-token` می‌گذارد و Bearer می‌فرستد؛ endpoint login با حساب واقعی `consulting` همچنان **NOT TESTED** است، چون credential یا نشست مرورگر کاربر در اختیار این اجرا نیست؛ هیچ password یا token تغییر نکرد. شمارش aggregate: 50 حساب client-eligible، 1 حساب غیر-client/admin، 0 counselor-linked account و 7 token row؛ هیچ شناسه/credentialی چاپ نشد.
- **وضعیت اولویت فوری:** علت schema برای خطای booking رفع شد و payload پس از ورود از نظر `counselor_id=13`، `service_id=1`، قیمت، slot و timezone سازگار است؛ اما valid-client POST روی 8010 و login واقعی **BLOCKED / NOT TESTED** هستند. برای بستن این دو مورد باید همان client از نشست امن خود login کند و نتیجه‌ی redacted (`status`, `message`, آیا Bearer ارسال شده) ثبت شود؛ token/password را در گزارش یا پیام نفرستید.
- **سایر فازها:** نتایج E2E ایزوله‌ی فازهای عمومی، پنل، lifecycle و finance/security که در جدول بالا ثبت شده‌اند معتبر باقی می‌مانند. این اصلاح به‌تنهایی آزمون write روی `consulting` یا آمادگی production را اثبات نمی‌کند.
- README اصلی backend که به‌اشتباه نبود endpointهای login/register را اعلام می‌کرد، اصلاح شد و اکنون مسیرهای login، token scoped و `EnsureClient` را مستند می‌کند.
