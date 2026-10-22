# 11 · ENGINEERING STANDARDS — stack, โครงสร้างโค้ด, naming, DoD

> อ้างอิงรูปแบบจาก `references/source/paotang/ARCHITECTURE.md` และ `AGENTS.md` (เฉพาะ**แพตเทิร์น** — ไม่คัดลอกโดเมน fintech)

---

## 1. Stack ที่ล็อก

| ชั้น | เทคโนโลยี | เวอร์ชัน |
|---|---|---|
| Backend | Laravel + Sanctum | 13.x |
| Language | PHP | 8.4+ |
| DB | PostgreSQL | 17+ |
| Cache/Queue | Redis | 7+ |
| Object storage | S3-compatible (private) | — |
| Mobile | Flutter + Riverpod + GoRouter + Dio | Flutter 3.35+ |
| Web | Angular + Tailwind | 20+ |
| Infra | Docker Compose (dev) / VPS + managed DB (prod) | — |
| Test | PHPUnit, Pest (optional), flutter_test, integration_test, k6 | — |
| CI | GitHub Actions | — |

**ห้าม** เพิ่ม technology ใหม่โดยไม่มี ADR (เช่น GraphQL, microservice, Kubernetes — ยังไม่จำเป็น)

---

## 2. โครงสร้าง Backend (Modular Monolith)

```
app/
├── Modules/
│   ├── Accounts/            # C1 Identity & Access
│   ├── Workspace/           # C2
│   ├── People/              # C3
│   ├── Authz/               # C4 (service กลาง)
│   ├── Consent/             # C5
│   ├── HealthRecord/        # C6–C8, C10 (documents)
│   ├── CareCrm/             # C9–C10
│   ├── Timeline/            # C11 (read model)
│   ├── Notifications/       # C12
│   ├── AiGateway/           # C13
│   ├── Audit/               # C14
│   └── Billing/             # C15
├── Shared/
│   ├── Http/                # middleware, problem+json, tracing
│   ├── Support/             # Uuid7, Clock, Money
│   └── Contracts/           # interfaces ข้ามโมดูล
└── Support/
```

**แต่ละโมดูลมี:**
```
Module/
├── Domain/          # entities, value objects, rules (ห้ามพึ่ง framework)
├── Application/     # use cases / services
├── Infrastructure/  # eloquent, repositories, events, adapters
├── Http/            # controllers, requests, resources
├── Policies/        # authorization
└── Tests/
```

**กฎ**
| ID | กฎ |
|---|---|
| **ES-01** | ห้าม query ข้าม schema ของโมดูลอื่น — ใช้ `Contracts/` interface หรือ domain event (N-06) |
| **ES-02** | Controller ≤ 30 บรรทัด, ไม่มี business logic |
| **ES-03** | ทุก use case เปิด public method เดียว (`execute`) และ return result object |
| **ES-04** | ห้ามใช้ `Model::query()` ดิบใน controller — ผ่าน repository/query object |
| **ES-05** | ทุก transition ของ aggregate อยู่ใน domain method ไม่ใช่ service |
| **ES-06** | Event ที่ publish ต้องเป็น immutable value object |

---

## 3. โครงสร้าง Flutter (feature-first)

```
lib/
├── app/            # bootstrap, router, theme, DI
├── core/           # network (dio + interceptors), storage, errors, clock
├── design_system/  # tokens, typography, spacing, widgets กลาง
├── shared/         # extensions, utils, models ที่ใช้ข้ามฟีเจอร์
└── features/
    ├── auth/
    ├── onboarding/
    ├── workspace/
    ├── dashboard/
    ├── care_tasks/
    ├── health_vault/
    ├── family_access/
    ├── ai_assistant/
    └── notifications/
```

**แต่ละฟีเจอร์:**
```
feature/
├── data/           # repository impl, dto, remote/local source
├── domain/         # entity, use case
├── presentation/   # screen, widget, controller (Riverpod notifier)
└── providers/
```

**กฎ**
| ID | กฎ |
|---|---|
| **ES-10** | Widget ≤ **250 บรรทัด** เกินแล้วต้องแยก (จาก `AGENTS.md`) |
| **ES-11** | ห้ามเรียก API / validate / navigate ใน widget — ต้องผ่าน controller/use case |
| **ES-12** | State: Riverpod (`AsyncNotifier`), ห้าม `setState` สำหรับ business state |
| **ES-13** | ทุก async flow มี state ครบ: loading / data / empty / error / retry |
| **ES-14** | ห้าม hardcode สี/spacing/ขนาดฟอนต์ — ใช้ design token |
| **ES-15** | ทุกหน้าจอต้องมี Semantics label และรองรับ text scale 200% |
| **ES-16** | Token/PIN ห้ามเก็บ plaintext — `flutter_secure_storage` เท่านั้น |
| **ES-17** | Route ใช้ GoRouter + guard ตามสถานะ auth/onboarding (deep link ต้องทำงาน) |

---

## 4. Naming

| สิ่ง | Convention | ตัวอย่าง |
|---|---|---|
| PHP class | PascalCase | `CareTaskTransitionService` |
| PHP method/var | camelCase | `completeTask()` |
| DB table | snake_case พหูพจน์ | `care_tasks` |
| DB column | snake_case | `due_at` |
| Permission | `domain.action` | `care_task.complete` |
| Route name | kebab-case | `care-tasks.complete` |
| Dart class | PascalCase | `CareTaskCard` |
| Dart file | snake_case | `care_task_card.dart` |
| Provider | camelCase + Provider | `careTaskListProvider` |
| Rule ID | `PREFIX-nnn` | `CT-004` |
| Test ID | `PREFIX-Tnn` | `CT-T04` |
| ADR | `ADR-nnn` | `ADR-006` |

---

## 5. Git & review

**Branch:** `main` (protected) ← `feat/*`, `fix/*`, `chore/*`
**Commit:** Conventional Commits — `feat(care-task): add verify transition`
**PR ต้องมี:**
- ลิงก์ Rule ID + Permission ID
- ไฟล์ `.ai/` ที่เป็นข้อกำหนดต้นทาง
- test ที่เพิ่ม/เปลี่ยน
- ถ้าเปลี่ยน schema → migration + อัปเดต `06`/`02`
- ถ้าเปลี่ยน API → OpenAPI + client update

**Reviewer checklist (บังคับ):**
- [ ] ทุก endpoint มี permission annotation
- [ ] มี negative test
- [ ] ไม่มี PHI ใน log/notification
- [ ] ไม่มี query ข้ามโมดูล
- [ ] ไม่มี widget เกิน 250 บรรทัด
- [ ] migration ย้อนกลับได้ (`down()` ใช้ได้)

---

## 6. Performance budgets

| จุด | เป้า |
|---|---|
| Dashboard aggregate | p95 < 400 ms (warm), < 1.2 s (cold) |
| List งาน 50 รายการ | p95 < 250 ms |
| Complete task | p95 < 300 ms |
| Doctor Brief สร้าง | < 8 s (async job + notify) |
| App cold start | < 2.5 s (device กลาง) |
|ขนาด APK/IPA | < 40 MB |
| Crash-free session | ≥ 99.5% |

---

## 7. Definition of Done (ต่อฟีเจอร์)

1. โค้ด merge ตาม standards §2/§3
2. Test ครบตาม [`10-TESTING.md`](10-TESTING.md) รวม negative
3. Permission ID + Rule ID อ้างใน PR และในโค้ด (annotation)
4. เอกสาร `.ai/` อัปเดตถ้ามีการเบี่ยง
5. OpenAPI อัปเดต + client ไม่พัง
6. ผ่าน PHI leak scan
7. UX review: empty/loading/error/offline ครบ
8. Accessibility: text scale 200%, contrast ≥4.5:1, screen reader label
9. Analytics event ที่จำเป็น (ไม่ส่ง PHI)
10. Rollback plan (feature flag หรือ revert ได้)

---

## 8. สิ่งที่ห้ามทำในโค้ด

- ❌ `Model::all()`, `->get()` โดยไม่มี scope บนตาราง health
- ❌ `Auth::user()->isAdmin()` แบบกระจาย — ต้องใช้ policy
- ❌ `try { ... } catch (\Exception $e) { return true; }` ใน authorization
- ❌ ใช้ `env()` นอก config
- ❌ commit `.env`, key, ข้อมูลผู้ใช้
- ❌ สร้าง helper global ที่เข้าถึง DB ข้ามโมดูล
- ❌ ใช้จำนวนแถว (count) เป็นตัวบอกว่ามีข้อมูลอยู่หรือไม่ ในที่ที่อาจ leak
