# 06 · DATA MODEL — ฐานข้อมูล, RLS, audit, retention

> อ้างอิง ADR: [ADR-006](DECISIONS.md#adr-006--ชื่อตารางมาตรฐานชุดเดียว), [ADR-007](DECISIONS.md#adr-007--postgresql-rls-ใช้เฉพาะ-tenant-isolation), [ADR-008](DECISIONS.md#adr-008--uuidv7-เก็บเป็น-native-uuid-ไม่ใช่-string)
> ชื่อตารางทั้งหมดอ้างจาก [`02-DOMAIN-MODEL.md §5`](02-DOMAIN-MODEL.md#5-ตารางมาตรฐาน-canonical-tables)

---

## 1. มาตรฐานทั่วไป

| หัวข้อ | ข้อกำหนด |
|---|---|
| RDBMS | PostgreSQL **17+** (ต้องใช้ในเทสต์ด้วย — ห้าม SQLite) |
| PK | `uuid` (native) เก็บ **UUIDv7** สร้างฝั่งแอป (ADR-008) |
| Time | `timestamptz` เก็บ UTC; `*_local_time` + `timezone` (IANA) เมื่อต้องแสดงเวลาท้องถิ่น |
| Version | `version integer not null default 1` บนตารางที่แก้ไขบ่อย (optimistic locking) |
| Timestamps | `created_at`, `updated_at` ทุกตาราง; `deleted_at` เฉพาะตารางที่ลบได้ตามกฎหมาย |
| Naming | snake_case พหูพจน์; FK = `<singular>_id`; index = `ix_<table>_<cols>` |
| Enum | ใช้ PostgreSQL enum **หรือ** lookup table — ห้ามใช้ string ว่าง |
| JSONB | ใช้กับ `scope`, `constraints`, `metadata` เท่านั้น และต้องมี schema validation ฝั่งแอป |
| Money | `bigint` หน่วยสตางค์ (ยังไม่ใช้จน ADR-011 ผ่าน) |
| Text | `citext` สำหรับ email; ชื่อบุคคลใช้ `text` (ห้ามตัดเพศ/คำนำหน้า) |

---

## 2. UUIDv7

```php
// app/Support/Id/Uuid7.php
final class Uuid7
{
    public static function generate(): string
    {
        // 48-bit ms timestamp | ver 7 | rand_a | var | rand_b
    }
}
```
- สร้างในระดับ application (ไม่พึ่ง extension) เพื่อ portability
- ห้ามใช้ UUID เป็นมาตรการความปลอดภัย — ต้องมี authorization ทุกครั้ง (SUMMARY.md เตือนไว้แล้ว)

---

## 3. คอลัมน์บังคับตามกลุ่มตาราง

| กลุ่ม | คอลัมน์บังคับเพิ่ม |
|---|---|
| tenant-scoped (ทุกตารางใน C2–C15) | `tenant_id not null` + `workspace_id not null` (FK composite `(workspace_id, tenant_id)`) — ADR-013 |
| health data (`health_*`, `medication_*`, `documents`) | `profile_id`, `recorded_by`, `source`, `version` |
| documents | `profile_id`, `category`, `sensitivity`, `storage_key`, `size`, `mime`, `checksum` (ADR-014) |
| delegations (legal authority) | `represented_person_id`, `representative_user_id`, `level` (`L1`/`L2`/`L3`), `evidence_document_id`, `verified_by`, `verified_at`, `expires_at`, `status`, `revoked_reason` (ADR-015) |
| grant/consent | `granted_by`, `valid_from`, `expires_at`/`withdrawn_at` |
| audit | `actor_id`, `actor_type`, `action`, `target_type`, `target_id`, `result`, `ip`, `user_agent`, `prev_hash`, `hash` |
| notification | `channel`, `template_key`, `status`, `attempts`, `sent_at`, `error_code` |

---

## 4. Row-Level Security (ADR-007 + ADR-013)

**ขอบเขต: กันข้าม tenant และข้าม workspace**

```sql
-- ต้องมี unique(id, tenant_id) บน workspaces เพื่อให้ FK composite ใช้อ้างได้
alter table workspaces add constraint uq_workspaces_id_tenant unique (id, tenant_id);

alter table care_tasks
  add constraint fk_care_tasks_workspace_tenant
  foreign key (workspace_id, tenant_id)
  references workspaces (id, tenant_id);

alter table care_tasks enable row level security;

create policy tenant_isolation on care_tasks
  using (
    tenant_id = current_setting('app.tenant_id', true)::uuid
    and workspace_id = current_setting('app.workspace_id', true)::uuid
  );
```

> ถ้า `app.tenant_id` / `app.workspace_id` ไม่ถูกตั้งค่า → `current_setting(..., true)` คืน NULL → policy เป็น false → **deny by default** (สอดคล้อง F-09)

**บังคับใช้:**
- ทุก connection ที่ให้บริการ request ต้องตั้ง **ทั้งสองค่า** ภายใน transaction เดียว:
  ```sql
  SET LOCAL app.tenant_id  = '<tenant-uuid>';
  SET LOCAL app.workspace_id = '<workspace-uuid>';
  ```
- job ในคิวต้องตั้งค่าเดียวกันก่อน query (ค่ามาจาก job payload ที่ถูกตรวจสิทธิ์แล้ว)
- superuser/owner ของ DB ใช้ได้เฉพาะ migration (ห้ามใช้ runtime)
- **ห้าม** ใช้ RLS ตัดสินระดับ field/profile — เป็นหน้าที่ Policy (F-01)

**ข้อควรระวังที่ต้องทดสอบ:** connection pool (PgBouncer ในโหมด transaction) + `SET LOCAL` หายหลัง transaction — ต้องมี integration test + `TEN-T02`

---

## 5. Audit

### 5.1 `audit_events` (ธุรกิจ)

บันทึก: ใครทำอะไรกับอะไร ผลเป็นอย่างไร ใช้สำหรับ "ประวัติการเข้าถึงข้อมูล"

### 5.2 `security_audit_logs` (ความปลอดภัย, append-only)

```sql
create table security_audit_logs (
  id            uuid primary key,
  seq           bigint generated always as identity,
  occurred_at   timestamptz not null default now(),
  actor_id      uuid,
  actor_type    text not null,          -- user|system|ai|platform_staff
  action        text not null,          -- permission id หรือ system event
  target_type   text,
  target_id     uuid,
  result        text not null,          -- allow|deny|error
  reason_code   text,
  workspace_id  uuid,
  metadata      jsonb not null default '{}'::jsonb,  -- ห้ามมี PHI
  prev_hash     bytea,
  hash          bytea not null
);
```

**กฎ (F-15)**
| ID | กฎ |
|---|---|
| **DM-A01** | DB user ของแอปมีสิทธิ์เฉพาะ `INSERT`, `SELECT` บนตารางนี้ — ไม่มี `UPDATE`/`DELETE` แม้แต่ admin |
| **DM-A02** | `hash = sha256(prev_hash ‖ canonical_json(row))` — ตรวจสอบย้อนหลังได้ |
| **DM-A03** | `metadata` ต้องผ่าน scrubber ก่อนเขียน (ห้ามชื่อ, ค่าแล็บ, ข้อความอิสระ) |
| **DM-A04** | partition รายเดือน + retention ตามนโยบาย (ค่าเริ่มต้น 5 ปี) |
| **DM-A05** | การอ่าน audit ต้องมี `audit_event.read` (staff) หรือ `audit_event.read_own` (เจ้าของ — [CR-001](changes/CR-001-permission-delta-v1.1.md)) |

---

## 6. การเข้ารหัส

| ระดับ | วิธี |
|---|---|
| At rest (volume) | KMS/disk encryption ของผู้ให้บริการ |
| At rest (field) | Laravel `encrypted` cast สำหรับ: `contact_points.value`, `documents.storage_key`, `delegations.evidence_reference`, `notes` ที่เป็นข้อความอิสระ |
| In transit | TLS 1.2+ ทุก endpoint; HSTS |
| Object storage | private bucket + SSE + signed URL ≤15 นาที — **ห้าม public bucket** |
| Backup | เข้ารหัส + แยก key + ทดสอบ restore รายไตรมาส |
| Key rotation | รายปี หรือเมื่อมีเหตุ |

---

## 7. Index ที่จำเป็น (ขั้นต่ำ)

| ตาราง | index | เหตุผล |
|---|---|---|
| ทุกตาราง tenant-scoped | `(tenant_id, workspace_id)` | RLS สองชั้น + query หลัก |
| `care_tasks` | `(workspace_id, status, due_at)`, `(workspace_id, profile_id, status)` | dashboard + escalation scan |
| `care_tasks` | `(template_id, scheduled_for)` **unique** | RC-001 |
| `task_assignments` | `(task_id, role)` unique where role='primary' | CT-001 |
| `access_grants` | `(grantee_user_id, profile_id, status)`, `(expires_at)` | ตรวจสิทธิ์ + job หมดอายุ |
| `consent_records` | `(profile_id, purpose, withdrawn_at)` | ตรวจ consent |
| `health_measurements` | `(profile_id, type, recorded_at desc)` | กราฟ |
| `notification_deliveries` | `(status, next_attempt_at)`, `(task_id)` | retry |
| `security_audit_logs` | `(occurred_at)`, `(actor_id)`, `(target_id)` | สืบสวน |
| `timeline_entries` | `(profile_id, occurred_at desc)` | timeline |

**Partitioning:** `health_measurements`, `timeline_entries`, `security_audit_logs`, `notification_deliveries` → range partition รายเดือนเมื่อเกิน ~10M แถว

---

## 8. Retention & deletion (ADR-016)

> **หลัก:** เราคือ *ผู้ควบคุมข้อมูลส่วนบุคคล* ตาม PDPA ไม่ใช่สถานพยาบาล — จึงยึดหลัก "เก็บเท่าที่จำเป็นตามวัตถุประสงค์" ไม่ใช่เลข 10 ปีของเวชระเบียนโรงพยาบาล

### 8.1 ตาราง retention (ค่าเริ่มต้น)

| ประเภท | ค่าเริ่มต้น | นับจาก |
|---|---|---|
| `health_measurements`, `health_conditions`, `allergies` | **60 เดือน** | `recorded_at` / `updated_at` |
| `medication_plans`, `medication_records` | **60 เดือน** | `updated_at` |
| `appointments` | **60 เดือน** | `scheduled_at` |
| `care_tasks`, `task_events`, `task_evidence` | **36 เดือน** | `created_at` |
| `documents` (medical) | **60 เดือน** | `created_at` |
| `documents` (identity / `legal_authority`) | **12 เดือนหลัง delegation สิ้นสุด** | `revoked_at` |
| `ai_conversations` | **6 เดือน** | `created_at` |
| `notification_deliveries` | payload **90 วัน** → metadata **24 เดือน** | `sent_at` |
| `audit_events`, `security_audit_logs` | **60 เดือน (fixed)** | `occurred_at` |
| backups | 35 วัน rolling + 12 monthly | — |

### 8.2 Inactivity

ไม่มีการ login **24 เดือน** → แจ้งเตือน 30/14/7 วัน → เสนอ export ฟรี → purge ข้อมูลสุขภาพของ workspace (audit ยังอยู่)

### 8.3 Legal hold

ตาราง `data_holds` (`workspace_id`, `profile_id?`, `reason`, `created_by`, `until`) — มี hold แล้ว **ห้าม purge ทุกกรณี แม้ผู้ใช้ขอ**

### 8.4 Tenant override

`tenants.retention_override` (JSONB) กำหนดได้เฉพาะภายใน **min/max** ต่อประเภท (health data: 24–120 เดือน) — ทุกการเปลี่ยนต้อง audit

### 8.5 Purge job

| ข้อ | ค่า |
|---|---|
| ตารางเวลา | รายวัน 03:00 UTC |
| Batch | 500 แถว/รอบ (resumable) |
| Dry-run | บังคับ 30 วันแรก (`RETENTION_DRY_RUN=true`) |
| ขั้นตอน | เลือกผู้เข้าเกณฑ์ → ตัด `data_holds` → purge → audit → metric |
| ห้าม | purge audit tables หรือแถวที่มี hold |

**กฎ**
| ID | กฎ |
|---|---|
| **DM-R01** | ลบข้อมูลสุขภาพ = soft delete + purge job + audit (F-16) |
| **DM-R02** | ลบ workspace = ปิดการเข้าถึงทันที, purge ตามกำหนด, ไม่ลบ audit |
| **DM-R03** | คำขอเข้าถึง/แก้ไข/ลบข้อมูลของเจ้าของ (PDPA) ต้องมี workflow + SLA ≤30 วัน |
| **DM-R04** | ห้ามลบ audit ที่ผูกกับข้อพิพาท/คดี หรือมี `data_holds` |
| **DM-R05** | การลบต้องไม่ทำให้ audit chain ขาด — purge เฉพาะตารางธุรกิจ |
| **DM-R06** | `retention_override` ต่ำกว่า min ที่กำหนดไม่ได้ (422) |
| **DM-R07** | Purge ต้อง idempotent — รันซ้ำต้องไม่ลบเกินหรือนับซ้ำ |
| **DM-R08** | ก่อน purge ครั้งแรกของ workspace ต้องมีประวัติแจ้งเตือน 3 ครั้ง (inactivity) |

---

## 9. Migration & seed

**ลำดับ migration (ห้ามสลับ):**
1. `tenants` (**seed 1 แถว `default`**), `workspaces` (+ `unique(id, tenant_id)`), `users`, `workspace_memberships`, `invitations`
2. `persons`, `contact_points`, `person_relationships`, `health_profiles`, `care_relationships`, `delegations`
3. `access_grants`, `consent_records` (+ RLS policies สำหรับ tenant)
4. `health_conditions`, `allergies`, `health_measurements`, `medication_plans`, `medication_records`
5. `documents`
6. `care_plans`, `care_task_templates`, `care_tasks`, `task_assignments`, `task_events`, `task_evidence`, `appointments`
7. `care_cases`, `interaction_logs`, `communication_preferences`, `notification_deliveries`
8. `timeline_entries` (view/materialized), `audit_events`, `security_audit_logs`, `data_holds`
9. `ai_conversations`, `ai_context_requests`, `ai_audit_logs`, `ai_usage_counters`
10. `subscriptions`, `entitlements`, `feature_flags`

**Seed ที่ต้องมี (dev/staging):** 1 tenant, 2 workspaces, 3 personas (owner/caregiver/viewer), 1 dependent profile, grants ที่ครบ/หมดอายุ/ถูกเพิกถอน, งานครบทั้ง 3 tier, measurement 30 วัน, 1 document
**Seed ต้องไม่มีข้อมูลจริงของผู้ใช้จริง** และต้องไม่ถูกใช้ใน production

---

## 10. ข้อห้าม

- ❌ เพิ่มคอลัมน์ `role` บน `users`
- ❌ เก็บ PHI ใน `metadata`, `error_code`, ชื่อไฟล์, หรือ log
- ❌ ใช้ `where`-less query กับตาราง health (ต้องมี workspace/profile เสมอ)
- ❌ query ตาราง tenant-scoped โดยไม่ตั้ง `app.tenant_id` + `app.workspace_id` (จะได้ 0 แถวหรือถูกปฏิเสธ)
- ❌ ส่ง `tenant_id` จาก client หรือเชื่อค่าที่ client ส่งมา (N-01)
- ❌ สร้างตาราง/คอลัมน์สำหรับ scope ที่ปิดใน MVP (`mental_health`, `lab_results` structured) — ใช้ `documents.category` แทน (ADR-014)
- ❌ ใช้ RLS แทน Policy
- ❌ ลบ `security_audit_logs` แม้ใน dev seed (ให้ truncate ทั้ง DB แทน)
