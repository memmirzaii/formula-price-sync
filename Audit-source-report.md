استخراج انجام شد. نتیجه را به شکل «Release Audit Matrix» می‌دهم تا مستقیماً مبنای فاز اصلاح و تست قرار بگیرد.

# ماتریس نهایی Audit نسخه v2.0.0

راهنمای شدت:

* P0 = مسدودکننده انتشار. خرابی مسیر اصلی یا احتمال خطای جدی.
* P1 = بسیار مهم. باید قبل از Stable اصلاح شود.
* P2 = مهم ولی غیرمسدودکننده.
* P3 = بهبود بعدی.

## A. Release Blockers

| ID      | شدت | بخش                   | محل                                                         | ایراد                                                                                           | اثر واقعی                                                                       | اصلاح لازم                                                    | تست پذیرش                                               |
| ------- | --- | --------------------- | ----------------------------------------------------------- | ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------- |
| FPS-001 | P0  | Formula Engine        | `includes/Engine/Formula_Parser.php:146,159,176,178`        | استفاده از `ctype` به جای تابع `ctype_digit()`                                                  | فرمول سفارشی در اجرای واقعی Fatal Error می‌دهد                                  | جایگزینی تمام موارد با `ctype_digit($ch)`                     | ارزیابی حداقل 20 فرمول معتبر و نامعتبر                  |
| FPS-002 | P0  | Server Cron           | `includes/Core/Cron_Manager.php:104-150`                    | Lock قبل از `run_sync()` ثبت می‌شود و `run_sync()` همان Lock را به‌عنوان اجرای همزمان رد می‌کند | Cron معتبر با `fps_sync_locked` شکست می‌خورد                                    | طراحی Acquire/Run/Release به شکل اتمیک                        | اجرای Endpoint با Token واقعی و مشاهده Schedule شدن Job |
| FPS-003 | P0  | Admin AJAX            | `includes/Admin/Settings_API.php:575-580`                   | فراخوانی `API_Manager::fetch_and_cache_rate()` در حالی که متد وجود ندارد                        | دکمه Refresh Rates در Runtime می‌شکند                                           | استفاده از API موجود، ترجیحاً `force_refresh()`               | AJAX واقعی با nonce و حساب مجاز                         |
| FPS-004 | P0  | Notifications         | `includes/API/Circuit_Breaker.php:167` / `Notifier.php:454` | Hook آرگومان نامعتبر `$previous` می‌فرستد و listener آرایه می‌خواهد                             | Circuit Breaker → Notification قابلیت اطمینان ندارد و PHP 8 TypeError محتمل است | تعریف Contract واحد برای Event Payload                        | Trigger واقعی Circuit Breaker و تست Telegram/SMS        |
| FPS-005 | P0  | Release QA            | `vendor/`                                                   | `vendor/bin/phpunit` داخل بسته وجود ندارد                                                       | تست‌های اعلام‌شده از خود Release Package قابل اجرا نیستند                       | Build واقعی با Composer و Vendor Dev خارج از Production در CI | اجرای `composer install && vendor/bin/phpunit` در CI    |
| FPS-006 | P0  | Formula → WooCommerce | `Formula_Parser.php`, `Calculator.php`                      | مسیر فرمول سفارشی بدون E2E coverage کافی است                                                    | Regression دوباره به Production راه پیدا می‌کند                                 | افزودن تست E2E از Meta محصول تا `set_price()`                 | Product fixture + formula + expected price              |

---

# B. هسته قیمت‌گذاری

| ID      | شدت | محل                                  | ایراد                                                    | اثر                                                           | راهکار                                                 |
| ------- | --- | ------------------------------------ | -------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------ |
| FPS-007 | P1  | `Settings_API.php:241`               | `rate_divisor` به صورت Float تقریباً آزاد ذخیره می‌شود   | امکان Unit Drift و ورودی غیرمجاز                              | فقط `1` یا `10` را قبول کن                             |
| FPS-008 | P1  | `API_Manager.php` / `Calculator.php` | واحد ریال/تومان وارد مسیر محاسبات می‌شود                 | با معماری سند بازتوسعه که Base باید همیشه ریال باشد مغایر است | Raw/Base Rate را همیشه Rial نگه دار؛ تبدیل فقط در View |
| FPS-009 | P1  | `Metaboxes.php:251`                  | `get_post_meta(...) ?: '9'`                              | مقدار واقعی `0` به 9 تبدیل می‌شود                             | استفاده از `metadata_exists()` یا Null-safe check      |
| FPS-010 | P1  | `Action_Scheduler_Handler.php:144`   | همان الگوی `?: 9.0`                                      | Tax=0 در Sync به 9% تبدیل می‌شود                              | اصلاح fallback                                         |
| FPS-011 | P1  | `Action_Scheduler_Handler.php`       | Currency fallback بیش از حد آزاد است                     | EUR/ارز ناقص ممکن است با نرخ دیگری قیمت‌گذاری شود             | Missing Required Rate = عدم Update                     |
| FPS-012 | P1  | `Calculator.php`                     | باید مرز Unit در تمام ورودی/خروجی‌ها صریح باشد           | ریسک قیمت 10× یا 0.1×                                         | تعریف Value Object یا حداقل Contract مستند             |
| FPS-013 | P2  | `Calculator.php`                     | باید overflow/precision برای قیمت‌های بسیار بزرگ تست شود | فروشگاه‌های طلا مستعد اعداد بزرگ‌اند                          | تست boundary و decimal policy                          |
| FPS-014 | P2  | `Rounding.php`                       | نیاز به ماتریس تست کامل rounding                         | اختلاف قیمت در سناریوهای مرزی                                 | تست تمام Ruleها با مقادیر مرزی                         |

---

# C. Queue و Action Scheduler

| ID      | شدت | محل                            | ایراد                                               | اثر                                                                                         | اصلاح                                        |                                  |
| ------- | --- | ------------------------------ | --------------------------------------------------- | ------------------------------------------------------------------------------------------- | -------------------------------------------- | -------------------------------- |
| FPS-015 | P1  | `Action_Scheduler_Handler.php` | Deduplication برای Scheduleها کافی نیست             | Triggerهای متوالی می‌توانند Jobهای تکراری تولید کنند                                        | Job identity / `as_has_scheduled_action()`   |                                  |
| FPS-016 | P1  | `Action_Scheduler_Handler.php` | Auto Update فقط Configuration دارد                  | `auto_update` و `update_schedule` ثبت شده‌اند، ولی Scheduling واقعی در Release دیده نمی‌شود | ثبت/حذف Recurring Action بر اساس تنظیمات     |                                  |
| FPS-017 | P1  | `process_synchronously()`      | Fallback به Sync synchronous می‌تواند سنگین شود     | Timeout/Memory Exhaustion                                                                   | fallback را Chunked و bounded کن             |                                  |
| FPS-018 | P1  | Bulk Notifications             | `Notifier.php:500-520`                              | تشخیص آخرین Chunk با transient شکننده است                                                   | پیام Completion زودتر یا نادرست ارسال می‌شود | Run ID + remaining counter اتمیک |
| FPS-019 | P2  | Queue                          | اجرای دوباره Chunk پس از retry باید idempotent باشد | احتمال Log/Update تکراری                                                                    | طراحی عملیات idempotent                      |                                  |
| FPS-020 | P2  | Queue                          | `50` محصول ثابت است                                 | برای همه Hostها مناسب نیست                                                                  | تنظیم adaptive/chunk setting                 |                                  |

---

# D. Server Cron

| ID      | شدت | محل                | ایراد                                                       | اثر                             | اصلاح                   |
| ------- | --- | ------------------ | ----------------------------------------------------------- | ------------------------------- | ----------------------- |
| FPS-021 | P0  | `Cron_Manager.php` | Lock Flow اشتباه                                            | Cron اصلی شکست می‌خورد          | Lock ownership          |
| FPS-022 | P1  | `Cron_Manager.php` | Token در Query String                                       | Secret وارد Access Log می‌شود   | Header-based secret     |
| FPS-023 | P1  | `Cron_Manager.php` | Rate Limit و Lock دو مفهوم جدا هستند اما Contract روشن نیست | Race Condition                  | Mutex مشخص با TTL/owner |
| FPS-024 | P2  | Cron Guide         | نیاز به دستور واقعی cPanel/DirectAdmin + curl دارد          | نصب برای کاربر مارکت سخت می‌شود | مستندات اجرایی کامل     |
| FPS-025 | P2  | Cron               | Response باید machine-readable نیز باشد                     | مانیتورینگ سخت‌تر می‌شود        | JSON optional response  |

---

# E. Notification Layer

| ID      | شدت | محل                   | ایراد                                                     | اثر                                  |
| ------- | --- | --------------------- | --------------------------------------------------------- | ------------------------------------ |
| FPS-026 | P0  | `Circuit_Breaker.php` | Event Payload شکسته                                       | Notification ناکارآمد                |
| FPS-027 | P1  | `Notifier.php`        | قرارداد Eventها در بخش‌های مختلف یکدست نیست               | Maintenance مشکل‌ساز                 |
| FPS-028 | P1  | Bulk notification     | Counter transient به Run اختصاص ندارد                     | چند Sync همزمان روی هم اثر می‌گذارند |
| FPS-029 | P2  | Telegram              | باید timeout/retry/backoff و error logging مشخص باشد      | اختلال شبکه                          |
| FPS-030 | P2  | SMS                   | Provider abstraction کافی باید تست integration داشته باشد | تفاوت API پنل‌ها                     |

---

# F. Product UI / Price Lock

| ID      | شدت | محل                           | ایراد                                                           | نتیجه                                 |
| ------- | --- | ----------------------------- | --------------------------------------------------------------- | ------------------------------------- |
| FPS-031 | P1  | `Product_Columns.php`         | Locked Product ممکن است قبل از نمایش Status، مقدار `null` بگیرد | به‌جای `Locked` علامت `—` دیده می‌شود |
| FPS-032 | P1  | `Product_Columns.php:271-273` | Sort ستون قیمت با `_fps_last_synced` انجام می‌شود               | Sort ظاهراً غلط است                   |
| FPS-033 | P1  | `Metaboxes.php`               | Price Lock باید برای Simple و Variation به‌صورت E2E تست شود     | احتمال inconsistency                  |
| FPS-034 | P2  | Product Columns               | محاسبه/دریافت Meta متعدد                                        | فشار DB روی catalog بزرگ              |
| FPS-035 | P2  | Product Columns               | Price Preview باید وضعیت rate/source را واضح کند                | خطای تصمیم کاربر                      |

---

# G. History / Reporting

| ID      | شدت | محل                | ایراد                                        | وضعیت              |
| ------- | --- | ------------------ | -------------------------------------------- | ------------------ |
| FPS-036 | P1  | `History_Page.php` | خروجی واقعی CSV است، نه XLSX                 | Feature Partial    |
| FPS-037 | P1  | Export             | باید فیلتر Date/Product/Reason مستقل تست شود | QA ناقص            |
| FPS-038 | P2  | Export             | حجم زیاد Log نیاز به Streaming دارد          | Memory Risk        |
| FPS-039 | P2  | History            | timezone باید صریحاً تعریف شود               | اختلاف تاریخ گزارش |

---

# H. License / Commercialization

| ID      | شدت | بخش                | ایراد                                                     |
| ------- | --- | ------------------ | --------------------------------------------------------- |
| FPS-040 | P0  | `Zhaket_Guard.php` | اعتبارسنجی License در حد کافی برای محصول تجاری اثبات نشده |
| FPS-041 | P0  | Release            | وجود Test License در سورس/Release بسیار نامناسب است       |
| FPS-042 | P1  | Licensing          | Failure/Timeout/Offline behavior باید مشخص شود            |
| FPS-043 | P1  | Licensing          | باید activation/deactivation/re-activation تست شود        |
| FPS-044 | P2  | Licensing          | باید Rate Limit سمت سرویس License کنترل شود               |

این بخش مخصوصاً برای انتشار در مارکت مهم است. افزونه‌ای که licensing واقعی دارد باید رفتار آن در قطع اینترنت، تغییر دامنه، migration و activation دوباره مشخص باشد.

---

# I. Uninstall / Migration

| ID      | شدت | محل             | ایراد                                                 | نتیجه             |
| ------- | --- | --------------- | ----------------------------------------------------- | ----------------- |
| FPS-045 | P1  | `uninstall.php` | `_fps_price_locked` حذف نمی‌شود                       | Database Residue  |
| FPS-046 | P1  | `uninstall.php` | بخشی از Optionهای جدید Notification/Cron حذف نشده‌اند | Residual Settings |
| FPS-047 | P1  | DB migration    | Migration باید روی نصب v1.1.1 واقعی اجرا شود          | Upgrade Risk      |
| FPS-048 | P2  | DB Installer    | Backfill درست است ولی باید idempotency تست شود        | Migration Safety  |
| FPS-049 | P2  | Uninstall       | باید تصمیم حفظ/حذف Log مستند باشد                     | Data Policy       |

---

# J. Security Audit

| ID      | شدت | ارزیابی                                                       |
| ------- | --- | ------------------------------------------------------------- |
| SEC-001 | P1  | Cron Secret در URL قرار دارد                                  |
| SEC-002 | P1  | باید تمام AJAXها nonce + capability را جداگانه تست کنند       |
| SEC-003 | P1  | تمام optionها باید allow-list شوند، مخصوصاً enumها            |
| SEC-004 | P1  | URL/SSRF validation نیاز به hardening بیشتر دارد              |
| SEC-005 | P2  | Provider API timeout باید محدود و ثابت باشد                   |
| SEC-006 | P2  | Error Messageهای Provider نباید Secret/API Key leak کنند      |
| SEC-007 | P2  | Logger باید Secret Redaction داشته باشد                       |
| SEC-008 | P2  | License/API tokenها در UI با input type مناسب نمایش داده شوند |

---

# K. تست‌ها

یکی از مهم‌ترین یافته‌های Audit همین بخش است.

تست‌های موجود:

```text
tests/Unit/
├── CalculatorTest.php
├── CircuitBreakerTest.php
├── FormatterTest.php
├── FormulaParserTest.php
├── NumberToWordsTest.php
└── RoundingTest.php

tests/Integration/
├── ActionSchedulerHandlerTest.php
├── DBInstallerTest.php
└── NotifierTest.php
```

این ساختار خوب است.

اما Coverage باید به این بخش‌ها برسد:

```text
Cron_Manager
Settings_API::sanitize
API_Manager
Product_Columns
Metaboxes
Zhaket_Guard
History Export
AJAX handlers
Uninstall
Upgrade migration
Bootstrap
```

و مهم‌تر:

**در Release ZIP فعلی PHPUnit قابل اجرای مستقیم نیست.**

بنابراین بین:

```text
Tests exist
```

و:

```text
Tests passed on this exact release artifact
```

فاصله وجود دارد.

---

# L. Regression Matrix اجباری قبل از Stable

من برای تأیید `v2.0.0` این 30 سناریو را اجباری می‌دانم:

```text
R01  Fresh Install
R02  Activate without WooCommerce
R03  Activate with WooCommerce
R04  Upgrade from previous version
R05  Create gold product
R06  Create currency product
R07  Formula custom
R08  Formula malformed
R09  Formula divide by zero
R10  Tax = 0
R11  Tax = 9
R12  Rial display
R13  Toman display
R14  Lock product
R15  Unlock product
R16  Lock variation
R17  Category bulk sync
R18  Tag bulk sync
R19  1 product sync
R20  50 products sync
R21  500+ products sync
R22  Duplicate sync trigger
R23  Provider failure
R24  Provider failover
R25  Circuit breaker
R26  Telegram alert
R27  SMS alert
R28  Server cron
R29  History export
R30  Full uninstall
```

نسخه‌ای که این 30 مسیر را بدون خطا پشت سر نگذارد، برای من Stable نیست.

---

# M. Definition of Done برای هر P0

برای اینکه تیم توسعه نتواند صرفاً فایل را تغییر دهد و Bug را «حل‌شده» اعلام کند، معیار پذیرش باید رفتاری باشد.

### FPS-001

```text
Formula: {base_rial} * 1.09
Expected: numeric result
PHP: 7.4 / 8.0 / 8.1 / 8.2 / 8.3 / 8.4
```

### FPS-002

```text
GET cron endpoint
→ authentication succeeds
→ rate refresh succeeds
→ exactly N chunks scheduled
→ HTTP 200
→ lock released
```

### FPS-003

```text
Admin → Refresh Rates
→ nonce accepted
→ provider request
→ cache updated
→ JSON success
→ no fatal
```

### FPS-004

```text
Provider failure
→ Circuit Breaker triggers
→ Event payload valid
→ Notifier receives expected structure
→ Telegram/SMS dispatch
→ no TypeError
```

---

# اولویت اصلاح واقعی

من توسعه را این ترتیب می‌برم:

```text
                 RELEASE HARDENING

        ┌───────────────────────────────┐
        │ P0 Runtime Bugs               │
        │ Formula / Refresh / Cron      │
        │ Circuit Breaker               │
        └───────────────┬───────────────┘
                        ↓
        ┌───────────────────────────────┐
        │ Financial Correctness         │
        │ Rial/Toman / Tax / Currency   │
        └───────────────┬───────────────┘
                        ↓
        ┌───────────────────────────────┐
        │ Queue Reliability              │
        │ Scheduler / Dedup / Retry     │
        └───────────────┬───────────────┘
                        ↓
        ┌───────────────────────────────┐
        │ Commercial Readiness          │
        │ License / Uninstall / Export │
        └───────────────┬───────────────┘
                        ↓
        ┌───────────────────────────────┐
        │ Automated QA                  │
        │ PHPUnit / Integration / E2E   │
        └───────────────────────────────┘
```

---

# امتیاز جدید پس از Audit

با تفکیک «وجود Feature» از «قابلیت اتکای Production»، ارزیابی دقیق‌تر من این است:

| معیار                            |        نتیجه |
| -------------------------------- | -----------: |
| تحقق سند بازتوسعه از نظر Feature |      **70%** |
| تحقق عملکردی واقعی               |      **55%** |
| کیفیت معماری                     |      **80%** |
| امنیت                            |      **68%** |
| Reliability                      |      **52%** |
| Test Readiness                   |      **40%** |
| Release Engineering              |      **40%** |
| Commercial Readiness             |      **45%** |
| **Overall Release Score**        | **≈ 55/100** |

## حکم نهایی

**v2.0.0 فعلی Stable نیست.**

اما بازنویسی کامل لازم نیست.

پروژه در نقطه‌ای قرار دارد که با یک دور:

**Release Hardening + Financial Logic Hardening + E2E QA**

می‌تواند به Stable برسد.

مهم‌ترین نکته این است که **Featureهای جدید را فعلاً بیشتر نکنید.** ابتدا همین 49 مورد Audit را بسته و سپس تست‌های Regression را اجرا کنید. بر اساس سند خود بازتوسعه نیز هدف v2.0.0 «بسته انتشار» بوده است، اما شواهد فعلی برای عبور از RC به Stable کافی نیست. 

من در این مرحله، **17 مورد P0/P1 واقعی** را مهم‌تر از باقی موارد می‌دانم. اینها باید تبدیل به Backlog اصلاحی نسخه `v2.0.0-rc2` شوند.
