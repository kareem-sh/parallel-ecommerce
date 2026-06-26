# شرح مقاييس k6 — للعرض ومقارنة Before vs After

دليل بالعربية لمقاييس **k6** التي تظهر في الطرفية (Terminal) وفي **Grafana**، وكيف تستخدمها لشرح **فرق الأداء** بين `/api/before/*` و `/api/after/*`.

> **مصدر البيانات:** بعد كل تشغيل: `storage/k6/<timestamp>/` (ملف JSON لكل اختبار + `run.log` إن شغّلت السuite كاملة).  
> **Grafana:** InfluxDB ← k6 يكتب تلقائياً عند التشغيل داخل Docker.

---

## 1. كيف تقرأ نتيجة k6 بسرعة

في نهاية كل اختبار ابحث عن:

| السطر | المعنى |
|--------|--------|
| `checks: 100.00% ✓` | **نجاح منطق الاختبار** (الأهم للعرض) |
| `http_req_duration: avg=… p(95)=…` | زمن الاستجابة |
| `http_reqs: … /s` | معدل الطلبات (RPS) |
| `http_req_failed: X%` | نسبة HTTP «فاشلة» لـ k6 — **انتبه:** 503/409 قد تُحسب فاشلة لكنها **مقصودة** |
| `ERRO thresholds on metrics …` | تجاوز حد — راجع أي metric بالاسم |

**قاعدة للعرض:**  
> **Checks = 100%** يعني السيناريو تحقق.  
> **After أسرع** = avg/p95 أقل + غالباً RPS أعلى لنفس الاختبار.  
> **After «أفضل» لا يعني دائماً error rate = 0** (مثلاً 503 = حماية سعة).

---

## 2. مقاييس HTTP الأساسية (تظهر في كل الاختبارات)

### 2.1 Average Response Time — `http_req_duration` (avg)

**ما هو:** متوسط الزمن من إرسال الطلب حتى اكتمال الرد (بالمللي ثانية).

**ماذا يخبرك:** تجربة «الطلب العادي» — ليس أسوأ حالة.

| جيد | سيء |
|-----|-----|
| after **أقل** من before لنفس req | avg يرتفع أثناء الاختبار (طابور/ازدحام) |
| req4 after ~80ms vs before ~250ms | ثوانٍ على GET بسيط |

**مثال للعرض (req 4):**  
> before: المستخدم ينتظر حساب التقرير داخل الطلب → avg عالي.  
> after: 202 فوري → avg منخفض جداً.

---

### 2.2 P95 Response Time — `http_req_duration` p(95)

**ما هو:** 95% من الطلبات أسرع من هذه القيمة؛ 5% أبطأ.

**ماذا يخبرك:** «ذيل البطء» — مهم للعرض أكثر من max.

| جيد | سيء |
|-----|-----|
| p95 قريب من avg نسبياً | p95 **أضعاف** avg (طوابير طويلة) |
| req9 after p95 ~1.5s vs before ~2s+ | p95 بالثواني على req5/load |

**مثال:** avg 400ms و p95 8s → معظم الطلبات OK لكن **5% يتأخرون بشدة** (before تحت ضغط).

---

### 2.3 P99 Response Time

**ما هو:** 99% أسرع من هذه القيمة.

**ماذا يخبرك:** أندر حالات البطء (GC، lock، cold cache).

| جيد | سيء |
|-----|-----|
| p99 قريب من p95 | p99 >> p95 (ذيل غير مستقر) |

---

### 2.4 Max Response Time — max

**ما هو:** أبطأ طلب واحد في التشغيل.

**ماذا يخبرك:** outlier — مفيد لاكتشاف spike واحد.

**للعرض:** إذا max >> p95 → «حدثت طلبات نادرة جداً بطيئة» (قبل التحسين).

---

### 2.5 Requests Per Second (RPS) — `http_reqs` rate

**ما هو:** عدد طلبات HTTP في الثانية.

**ماذا يخبرك:** **قدرة التحمّل (throughput)**.

| جيد | سيء |
|-----|-----|
| after **RPS أعلى** من before (req 5, 9) | RPS ينهار والـ VUs ثابتة |
| req5 after ~150 req/s vs before ~48 | RPS منخفض + errors عالية |

**مثال req 5:**  
> before = app واحد → RPS محدود.  
> after = app + app2 + cache → RPS أعلى.

---

### 2.6 Total Requests — `http_reqs` count

**ما هو:** إجمالي الطلبات في مدة الاختبار.

**ماذا يخبرك:** هل الاختبار «اشتغل فعلاً» (sanity check).

**مثال:** 30s × 100 VU → آلاف الطلبات متوقعة. رقم قليل جداً = مدة أو VUs خطأ.

---

### 2.7 Active Virtual Users — `vus`

**ما هو:** عدد المستخدمين الافتراضيين **النشطين الآن**.

**مثاوم للعرض req 9:** يجب أن ترى `100/100 VUs` طوال 30s.

---

### 2.8 Maximum Configured VUs — `vus_max`

**ما هو:** أقصى VUs في السيناريو.

**مثال req 9:** `vus_max: 100` يثبت «100 مستخدم متزامن».

---

### 2.9 Error Rate % — `http_req_failed`

**ما هو:** نسبة الطلبات التي يعتبرها k6 «فاشلة» (غير 2xx أو 0).

**⚠️ مهم للمشروع:**

| الاختبار | error rate عالي — هل مشكلة؟ |
|----------|---------------------------|
| req2 after | **لا** — 503 = حارس سعة |
| req7 after | **لا** — 409 = lock |
| req8 before | **نعم متوقع** — 402 فشل دفع |
| req9 after | **لا بالضرورة** — 503 graceful |
| req5 / req6 reads | **نعم مشكلة** — يجب ~0% |

**للعرض:**  
> لا تعتمد على error rate وحده — انظر **checks** والـ metrics المخصصة.

---

### 2.10 Successful Checks % — `checks`

**ما هو:** نسبة `check()` التي نجحت (status، headers، JSON).

**الحد في المشروع:** غالباً `rate>0.95` أو `>0.98`.

| جيد | سيء |
|-----|-----|
| **100%** | &lt; 95% → `ERRO thresholds on metrics 'checks'` |

**هذا أهم رقم للجنة:** يثبت أن **السلوك الصحيح** حصل (ليس فقط «رد سريع»).

---

### 2.11 Dropped Iterations

**ما هو:** iterations لم تكتمل (timeout، graceful stop).

| جيد | سيء |
|-----|-----|
| 0 | &gt; 0 → زِد المدة أو قلّل VUs |

---

### 2.12 Iteration Duration Mean

**ما هو:** متوسط وقت دورة `default function()` كاملة (قد تشمل sleep أو عدة طلبات).

**الفرق عن http_req_duration:**  
- **http_req_duration** = طلب HTTP واحد.  
- **iteration_duration** = كل ما يفعله VU في دورة (req9: sleep للموجة + GET).

**req 9:** iteration أعلى من http لأن فيه `waitForSynchronizedBurst()`.

---

### 2.13 Iteration Duration P95

**ما هو:** p95 لمدة الدورة كاملة.

**مفيد في req 10** (عدة routes في iteration واحدة).

---

## 3. مقاييس فرعية (اختيارية للشرح التقني)

| Metric | المعنى |
|--------|--------|
| `http_req_waiting` | وقت انتظار **السيرفر** (TTFB) — الأهم للأداء |
| `http_req_connecting` | وقت فتح TCP |
| `http_req_blocked` | انتظار socket |
| `http_req_receiving` | تحميل body الرد |
| `data_received` / `data_sent` | حجم البيانات |

**للعرض:** إذا **waiting** عالي → bottleneck في PHP/MySQL/Redis وليس الشبكة.

---

## 4. مقاييس مخصصة حسب المتطلب (Custom Metrics)

### Req 1 — Race (المخزون)

| Metric | before | after |
|--------|--------|-------|
| `orders_accepted_without_stock_guard` | يقبل طلبات زائدة | — |
| `safe_rejected_rate` | — | يرفض بأمان عند نفاد stock |

**الفرق للعرض:** before = قبول خاطئ؛ after = رفض آمن + مخزون صحيح.

---

### Req 2 — Capacity

| Metric | before | after |
|--------|--------|-------|
| `capacity_rejected_rate` | — | نسبة **503** |

**الفرق:** after يرفض بـ 503 بدل الانهيار — error rate عالي **متوقع**.

---

### Req 3 — Queue (الطابور)

| Metric | before | after |
|--------|--------|-------|
| `queued_order_response_rate` | — | نسبة **201** (طلب + job بالخلفية) |

**الفرق:** after يرد 201 بسرعة؛ `SendOrderReceiptJob` على **worker** (لا تحتاج `queue:work` يدوياً).

---

### Req 4 — Batch (التقارير)

| Metric | before | after |
|--------|--------|-------|
| `report_queued_rate` | — | نسبة **202** |

**الفرق للأداء:**

| | before | after |
|---|--------|-------|
| avg | ~250ms+ (sync) | ~70–100ms (queue only) |
| العمل الثقيل | داخل HTTP | worker + `BuildDailySalesSummaryJob` |

---

### Req 5 — Load / Load Balancer

لا metric مخصص — استخدم **RPS + avg + checks**.

**للعرض:**

| | before | after |
|---|--------|-------|
| nginx | `app` واحد | `app` + `app2` |
| RPS | أقل | أعلى |
| Header | `X-Backend-Version: before` | `after` |

---

### Req 6 — Cache

| Metric | before | after |
|--------|--------|-------|
| `cache_hit_rate` | ~0 | يرتفع بعد الطلب الثاني |
| `hot_products_read_ms` | ثابت عالي | ينخفض عند hit |
| Header | `X-Backend-Cache: none` | `hit` / `miss` |

**الفرق:** after يقرأ من Redis → avg/p95/trend أقل.

---

### Req 7 — Distributed Lock

| Metric | before | after |
|--------|--------|-------|
| `stock_adjust_accepted` | ~800+ (200 كثيرة) | ~60 (صحيح) |
| `lock_contention_rate` | — | نسبة **409** (طبيعي) |
| teardown log | remaining عالي | remaining = 0 |

**الفرق:** ليس «سرعة» بل **صحة** — after أبطأ HTTP لكن **inventory صحيح**.

---

### Req 8 — ACID

| Metric | before | after |
|--------|--------|-------|
| `partial_failure_rate` | فشل جزئي | — |
| `rollback_rate` | — | rollback كامل |

---

### Req 9 — Stress

| Metric | before | after |
|--------|--------|-------|
| `system_collapse_signals` | 500/502/0 | يجب **0** |
| `graceful_capacity_rejections` | — | عدد **503** |
| avg / p95 / RPS | أبطأ | أسرع + throughput أعلى |

**للعرض:**  
> 503 على after = **حماية** وليس انهيار.  
> collapse = health/data loss (teardown checks).

---

### Req 10 — Benchmark

| Metric | before | after |
|--------|--------|-------|
| `products_read_ms` | Trend | Trend |
| `hot_products_read_ms` | Trend | Trend |
| `benchmark_duration_ms` | من JSON `duration_ms` | من JSON |
| `benchmark_cache_hit_rate` | — | after فقط |

**Header:** `X-Benchmark-Bottleneck` — before: DB scan؛ after: redis-cache.

---

## 5. جدول مقارنة سريع — ماذا تقول في العرض؟

| Metric | before (قديم) | after (محسّن) | جملة للعرض |
|--------|---------------|---------------|------------|
| **avg** | أعلى | أقل | «الطلب Typical أسرع» |
| **p95** | أعلى / ذيل طويل | أقل | «حتى المستخدم البطيء تحسّن» |
| **RPS** | أقل | أعلى | «النظام يخدم المزيد في الثانية» |
| **checks** | 100% | 100% | «السلوك صحيح» |
| **http_req_failed** | حسب req | قد يرتفع (503/409) | «رفض متحكم ≠ انهيار» |
| **cache_hit_rate** | 0 | عالي | «Redis يخدم القراءات» |
| **report_queued_rate** | — | 100% | «API سريع، العمل في worker» |

---

## 6. أمثلة أرقام من آخر تشغيل كامل (20 اختبار)

المجلد: `storage/k6/20260620-105104/`

| الاختبار | checks | ملاحظة أداء |
|----------|--------|-------------|
| req4-batch-before | 100% | sync — avg أعلى |
| req4-batch-after | 100% | avg ~73ms، `report_queued_rate` 100% |
| req5-load-before | 100% | ~48 req/s |
| req5-load-after | 100% | ~68 req/s، LB |
| req3-queue-after | 100% | `queued_order_response_rate` 100% |
| req7-lock-before | 100% | remaining 945/1000 (race) |
| req7-lock-after | 100% | remaining 0/60 |
| req9-stress-* | 100% | after أسرع avg + RPS أعلى |

---

## 7. Grafana — أي panels تقابل أي metric؟

| Panel | metric k6 |
|-------|-----------|
| Average Response Time | `http_req_duration` avg |
| P95 / P99 | `http_req_duration` p95 / p99 |
| Max | max |
| RPS | `http_reqs` rate |
| Error Rate | `http_req_failed` |
| Checks | `checks` rate |
| VUs | `vus` / `vus_max` |

**خطوات العرض:** شغّل before test → screenshot → شغّل after test → قارن نفس الـ panel.

---

## 8. أخطاء Terminal الشائعة — ماذا تشرح؟

| الرسالة | المعنى | هل مشكلة؟ |
|---------|--------|-----------|
| `ERRO thresholds on metrics 'checks'` | checks &lt; 95% | **نعم** — راجع API |
| `ERRO … 'http_req_duration'` | p95 تجاوز الحد | راجع req3/4/5 |
| `ERRO … 'lock_contention_rate'` | 409 كثيرة req7 | **غالباً OK** — القفل يعمل |
| `http_req_failed` عالي req2 after | 503 | **OK** — capacity guard |

---

## 9. أوامر التشغيل

```powershell
# اختبار واحد + JSON
.\scripts\run-k6-single.ps1 -Test req5-load-before
.\scripts\run-k6-single.ps1 -Test req5-load-after

# كل الـ 20 (migrate + seed)
.\scripts\run-k6-requirements.ps1
```

---

## 10. خلاصة جملة واحدة للمشروع

> **Before:** بطيء، monolith، بدون cache/queue/LB — يظهر في **avg/p95/RPS**.  
> **After:** cache + worker + load balancer + capacity + locks — **avg/p95/RPS أفضل** مع **checks 100%**؛ بعض **503/409** مقصودة لحماية النظام وليست انهياراً.
