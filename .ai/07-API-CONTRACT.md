# 07 · API CONTRACT — convention, endpoint, error model

> อ้างอิง ADR: [ADR-005](DECISIONS.md#adr-005--api-เป็น-workspace-scoped-ทั้งหมด), [ADR-010](DECISIONS.md#adr-010--dashboard-ใช้-endpoint-รวมตัวเดียว)
> ทุก endpoint ต้องผ่าน [`03-AUTHORIZATION.md §8`](03-AUTHORIZATION.md#8-วิธีเพิ่ม-endpoint-ใหม่-checklist-บังคับ) checklist

---

## 1. Convention

| หัวข้อ | ข้อกำหนด |
|---|---|
| Base | `/api/v1` |
| Format | JSON (`application/json`), UTF-8 |
| Scope | ทุก resource อยู่ใต้ `/workspaces/{workspace}` (ADR-005) |
| Auth | Laravel Sanctum (token/SPA); `Authorization: Bearer <token>` |
| Versioning | path version `/v1`; breaking change = `/v2`; ห้ามลบ field ใน v1 โดยไม่ deprecate 1 release |
| Envelope | `{"data": ..., "meta": {...}}` สำหรับ list; `{"data": ...}` สำหรับ single |
| Error | RFC 9457 `application/problem+json` |
| Pagination | cursor-based: `?cursor=...&limit=20` (max 100); `meta.next_cursor` |
| Filtering | whitelist เท่านั้น นอกนั้น → 422 |
| Sorting | `?sort=-due_at` (prefix `-` = desc) |
| Sparse fieldset | `?fields=id,status,due_at` |
| Idempotency | header `Idempotency-Key` บังคับกับ POST ที่สร้างข้อมูล |
| Concurrency | `If-Match: <version>` บังคับกับ PATCH ของ task/measurement |
| Trace | ทุก response มี `X-Request-Id`; log ผูกกัน |
| Rate limit | 60 req/min ต่อผู้ใช้, 10 req/min ต่อ endpoint ที่สร้าง AI/report |

---

## 2. Error model

```json
{
  "type": "https://api.luckycare.example/problems/out-of-scope",
  "title": "Resource not accessible",
  "status": 404,
  "code": "out_of_scope",
  "detail": "ไม่พบข้อมูลที่ร้องขอ",
  "trace_id": "01J9..."
}
```

| code | status | ใช้เมื่อ |
|---|---|---|
| `unauthenticated` | 401 | |
| `out_of_scope` | 404 | ไม่อยู่ใน workspace/scope (ไม่เปิดเผยการมีอยู่) |
| `forbidden` | 403 | อยู่ใน scope แต่ไม่มี permission |
| `no_consent` | 403 | ไม่มี consent ที่ใช้ได้ |
| `domain_rule` | 409 | สถานะไม่อนุญาต |
| `version_conflict` | 412 | `If-Match` ไม่ตรง |
| `validation` | 422 | |
| `rate_limited` | 429 | + `Retry-After` |
| `authorization_unavailable` | 503 | F-09 |

> **`detail` ห้ามมีข้อมูลสุขภาพ** และห้ามบอกว่า "มีข้อมูลอยู่แต่คุณไม่มีสิทธิ์" (ใช้ 404 แทน)

---

## 3. Endpoint catalog

### 3.1 Auth & Me

| Method | Path | Permission |
|---|---|---|
| POST | `/auth/register` | — |
| POST | `/auth/login` | — |
| POST | `/auth/otp/request` \| `/auth/otp/verify` | — |
| POST | `/auth/pin/setup` \| `/auth/pin/verify` | — |
| POST | `/auth/logout` | — |
| GET | `/me` | — |
| GET | `/me/workspaces` | — (คืนเฉพาะที่ตนเป็นสมาชิก) |
| PATCH | `/me` | — |

### 3.2 Workspace & Membership

| Method | Path | Permission |
|---|---|---|
| POST | `/workspaces` | — |
| GET | `/workspaces/{w}` | `workspace.read` |
| PATCH | `/workspaces/{w}` | `workspace.update` |
| GET | `/workspaces/{w}/members` | `workspace.member.read` |
| POST | `/workspaces/{w}/invitations` | `workspace.member.invite` |
| POST | `/invitations/{token}/accept` | — |
| DELETE | `/workspaces/{w}/members/{user}` | `workspace.member.remove` |

### 3.3 Person & Profile

| Method | Path | Permission |
|---|---|---|
| GET/POST | `/workspaces/{w}/persons` | `person.read` / `person.create` |
| GET/PATCH | `/workspaces/{w}/persons/{p}` | `person.read` / `person.update` |
| POST | `/workspaces/{w}/persons/{p}/merge` | `person.merge` |
| GET | `/workspaces/{w}/profiles/{profile}` | `health_profile.read` |
| GET/POST | `.../profiles/{profile}/conditions` | `health_condition.read` / `health_condition.create` |
| PATCH | `.../conditions/{c}` | `health_condition.correct` |
| GET | `.../profiles/{profile}/allergies` | `allergy.read` |
| PATCH | `.../allergies/{a}` | `allergy.update` (ใช้สำหรับเพิ่ม/แก้รายการ — catalog ไม่มี `allergy.create`) |

### 3.4 Access & Consent

| Method | Path | Permission |
|---|---|---|
| GET/POST | `/workspaces/{w}/profiles/{profile}/grants` | `access_grant.read` / `.create` |
| PATCH | `.../grants/{grant}` | `access_grant.update` |
| POST | `.../grants/{grant}/revoke` | `access_grant.revoke` |
| GET/POST | `.../consents` | `consent.read` / `consent.record` |
| POST | `.../consents/{consent}/withdraw` | `consent.withdraw` |
| POST | `/workspaces/{w}/break-glass/requests` | `break_glass.request` |
| POST | `/workspaces/{w}/break-glass/requests/{r}/approve` | `break_glass.approve` |

### 3.5 Health data

| Method | Path | Permission |
|---|---|---|
| GET/POST | `.../profiles/{profile}/measurements` | `health_measurement.read` / `.create` |
| PATCH/DELETE | `.../measurements/{m}` | `.correct` / `.delete` |
| GET | `.../measurements/trend?type=&from=&to=` | `health_measurement.read` |
| GET/POST | `.../medication-plans` | `medication_plan.*` |
| POST | `.../medication-plans/{plan}/discontinue` | `medication_plan.discontinue` |
| POST | `.../medication-records` | `medication_record.create` |
| GET/POST | `.../documents` (multipart) | `medical_document.read` / `.upload` |
| POST | `.../documents/{d}/share` | `medical_document.share` |
| GET | `.../documents/{d}/download` | `medical_document.read` (signed, ≤15m) |

### 3.6 Care Coordination

| Method | Path | Permission |
|---|---|---|
| GET/POST | `/workspaces/{w}/care-tasks` | `care_task.read` / `.create` |
| GET | `/workspaces/{w}/care-tasks/{task}` | `care_task.read` |
| POST | `.../care-tasks/{task}/assign` | `care_task.assign` |
| POST | `.../care-tasks/{task}/accept` \| `/decline` | `care_task.accept` |
| POST | `.../care-tasks/{task}/start` | `care_task.start` |
| POST | `.../care-tasks/{task}/complete` | `care_task.complete` |
| POST | `.../care-tasks/{task}/block` \| `/unblock` | `care_task.block` |
| POST | `.../care-tasks/{task}/verify` \| `/reject` | `care_task.verify` |
| POST | `.../care-tasks/{task}/reopen` | `care_task.reopen` |
| POST | `.../care-tasks/{task}/cancel` | `care_task.cancel` |
| GET | `.../care-tasks/{task}/events` | `care_task.read` |
| GET/POST | `.../care-task-templates` | `care_task.create` |
| GET/POST | `.../appointments` | `appointment.*` |

**`complete` body:**
```json
{
  "outcome": "done",
  "note": "optional, ≤500 chars",
  "evidence": [{"type":"photo","document_id":"..."},{"type":"value","metric":"glucose","value":112,"unit":"mg/dL"}]
}
```
- tier `routine` → `outcome` เท่านั้น
- tier `clinical` → ต้องมี `evidence` ≥1 ตาม `care_plans.evidence_policy` (มิฉะนั้น 422 `CT-T05`)

### 3.7 Dashboard (aggregate — ADR-010)

```
GET /workspaces/{w}/dashboard?profile_id={profile}
```
คืนเฉพาะการ์ดที่ผู้ใช้มีสิทธิ์ แต่ละการ์ดมี `visible: bool` + `reason` (ถ้าไม่ visible)

```json
{
  "data": {
    "family": {"members": [{"person_id":"...","profile_id":"...","ring":"inner","status":"yellow"}]},
    "alerts": [{"level":"critical","person_id":"...","code":"vital_high","task_id":"..."}],
    "tasks_today": {"total": 6, "done": 3, "items": [...]},
    "vitals": [{"type":"bp","values":[...],"status":"high"}],
    "next_appointment": {"profile_id":"..."},
    "break_glass_active": false
  },
  "meta": {"generated_at":"...","cache_ttl":30}
}
```
**ห้าม** ส่งฟิลด์ที่ผู้ใช้ไม่มีสิทธิ์แม้จะ `null` — ใช้การละฟิลด์ + `visible:false`

**`person_id` กับ `profile_id` ต้องมีทั้งคู่** — `person_id` คือตัวบุคคล (ใช้เป็น key ของรายการ)
ส่วน `profile_id` คือ health profile ที่ **ตัวกรอง `?profile_id=` และ endpoint สุขภาพทุกตัวรับ**
ถ้าไม่มี `profile_id` client จะ drill-down จาก family switcher ไม่ได้
(แก้เมื่อ 2026-10-23 ระหว่างทำ FE-2: `family.members[]` ไม่มี `profile_id` และ
`next_appointment.person_id` เคยใส่ค่า profile id ทำให้ payload ขัดกันเอง)

### 3.8 Reports & AI

| Method | Path | Permission |
|---|---|---|
| POST | `/workspaces/{w}/profiles/{profile}/reports/doctor-brief` | `health_report.export` + `data_export.*` |
| GET | `/reports/{report}/download` | ผูก grant + signed ≤15m |
| POST | `/workspaces/{w}/profiles/{profile}/ai/conversations` | `ai.health_context.use` + consent `ai_processing` |
| GET | `.../ai/conversations/{c}` | `ai.conversation.read` |

### 3.9 Webhooks

| Method | Path | หมายเหตุ |
|---|---|---|
| POST | `/webhooks/line` | ตรวจ `X-Line-Signature`; **ห้าม**คืนข้อมูลสุขภาพในข้อความตอบกลับ |
| POST | `/webhooks/push/receipt` | อัปเดต `notification_deliveries` |

### 3.10 Self-service (v1.1.0 — อยู่นอก workspace scope ตาม ADR-005)

| Method | Path | Permission |
|---|---|---|
| GET | `/me/notification-preferences` | `notification.preference.read` |
| PATCH | `/me/notification-preferences` | `notification.preference.update` |
| GET | `/me/notifications/deliveries` | `notification.delivery.read` |
| GET | `/me/audit-events?profile_id={id}` | `audit_event.read_own` |

**ข้อกำหนดเฉพาะ**
- ใช้ scope `self_membership:<membership_id>` (สามตัวแรก) และ `owned_profile_audit:<person_id>` (ตัวสุดท้าย)
- `PATCH /me/notification-preferences` **ห้าม**แตะ `consent_records` (ABD-008/ABD-016) — ถ้า request มี field เชิง consent → `422`
- `/me/audit-events` คืนเฉพาะ event ที่ actor เป็น subject หรือเป็นเจ้าของ/ผู้แทนของ profile; ห้ามคืนกิจกรรมของสมาชิกคนอื่น
- การอ่าน audit ผ่าน endpoint นี้ก็ถูกบันทึก audit ด้วย (AUTH-010)

---

## 4. กฎ API (AP-xx)

| ID | กฎ |
|---|---|
| **AP-001** | ทุก endpoint ต้องประกาศ permission ID ใน annotation/route middleware |
| **AP-002** | ห้ามรับ `tenant_id`/`workspace_id`/`profile_id` จาก body — ใช้ค่าจาก server context/path เท่านั้น; ถ้าส่งมาก็ไม่เชื่อ (ADR-013) |
| **AP-003** | List endpoint ต้องถูกจำกัดด้วย scope เสมอ — ไม่มี "list ทั้งระบบ" |
| **AP-004** | Export/report ต้องกรองแถวตามสิทธิ์ของผู้ขอ ไม่ใช่สิทธิ์ของเจ้าของรายงาน |
| **AP-005** | File download ต้องตรวจสิทธิ์ **ตอนกด** และ URL มีอายุ ≤15 นาที |
| **AP-006** | การกระทำที่มี side effect ต้อง idempotent (header `Idempotency-Key`) |
| **AP-007** | Transition endpoint ต้องคืน `version` ใหม่เพื่อให้ client ทำ optimistic locking ต่อ |
| **AP-008** | ห้าม leak การมีอยู่ของ resource ผ่าน 403/404 ที่ไม่สม่ำเสมอ (ใช้ 404 ตาม §2) |
| **AP-009** | AI endpoint ต้องคืน `disclaimer` + `sources[]` (อ้าง record id ที่ใช้) |
| **AP-010** | Webhook ต้องตรวจ signature + replay protection (timestamp + nonce) |

---

## 5. Idempotency & Outbox

- `Idempotency-Key` เก็บ 24 ชม. ใน `idempotency_keys` (key, actor, endpoint, request_hash, response)
- การสร้างงาน + สร้าง notification ต้องใช้ **transactional outbox**: เขียน `outbox_messages` ใน transaction เดียวกับข้อมูล แล้ว relay
- เหตุผล: กัน "บันทึกงานสำเร็จแต่แจ้งเตือนไม่ส่ง" และ "ส่งซ้ำเมื่อ retry"

---

## 6. สิ่งที่ต้องมีใน OpenAPI

- ทุก endpoint: permission ที่ต้องใช้ (x-permission), scope ที่ต้องมี, error ที่เป็นไปได้
- schema ของ `complete`, `dashboard`, `problem+json`
- ตัวอย่างสำหรับ negative case (403/404/409)

> generate จาก annotation แล้ว commit ลง repo — ใช้เป็น contract test กับ mobile
