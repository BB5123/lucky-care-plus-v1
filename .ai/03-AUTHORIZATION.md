# 03 · AUTHORIZATION — โมเดลสิทธิ์ การบังคับใช้ และการเพิกถอน

> **Normative parent:** [`docs/baseline-v1.0.0/`](../docs/baseline-v1.0.0/) — permission ID, role, matrix, test ID ทุกตัวมาจากที่นั่น
> ไฟล์นี้อธิบาย *วิธีประกอบ* กลไกเหล่านั้นเป็นระบบที่รันได้ ไม่ได้ประกาศ permission ใหม่

---

## 1. องค์ประกอบของสิทธิ์ (6 ชั้น)

การอนุญาต 1 ครั้ง = ผลรวมของ 6 ชั้น **ต้องผ่านทุกชั้นที่เกี่ยวข้อง**

| ชั้น | ตอบคำถาม | เก็บที่ไหน |
|---|---|---|
| **RBAC** | ผู้ใช้มีบทบาทอะไรใน workspace นี้ | `workspace_memberships.role` |
| **ReBAC** | ผู้ใช้สัมพันธ์กับเจ้าของข้อมูลอย่างไร | `care_relationships`, `access_grants.grantee_user_id` |
| **ABAC / Scope** | ข้อมูลประเภทใด ของใคร ช่วงเวลาใด | `access_grants.resource_scope`, `valid_from`, `expires_at` |
| **Consent** | มีความยินยอมที่ใช้ได้กับวัตถุประสงค์นี้หรือไม่ | `consent_records` |
| **Entitlement** | แพ็กเกจอนุญาตฟีเจอร์นี้หรือไม่ | `entitlements` |
| **Domain rule** | สถานะ/เงื่อนไขธุรกิจอนุญาตหรือไม่ | aggregate methods |

> **ห้ามใช้ RBAC อย่างเดียว** — นี่คือเหตุผลที่ `family_admin` ต้องไม่เป็นสิทธิ์ครอบจักรวาล (F-03)

---

## 2. ลำดับตัดสินใจ (บังคับ)

```
request
  → [1] authenticate()                    // 401 ถ้าไม่ผ่าน
  → [2] resolveWorkspace()                // 404 ถ้าไม่อยู่ใน scope (ไม่เปิดเผยการมีอยู่)
  → [3] resolveRelationshipAndRole()      // 403 ถ้าไม่มีบทบาทที่รองรับ action
  → [4] resolveResourceScope()            // 403 ถ้าไม่มี permission ต่อ profile+type
  → [5] checkConsent(purpose)             // 403 ถ้าไม่มี consent ที่ใช้ได้
  → [6] checkDomainRules()                // 409/422 ถ้าสถานะไม่อนุญาต
  → [7] audit() + execute
```

**Pseudocode ที่ต้องเป็นแบบนี้เท่านั้น:**

```php
final class Authorize
{
    public function __invoke(Request $r, string $permission, ResourceRef $res, ?Purpose $p = null): Decision
    {
        $actor = $this->auth->user($r);                  // [1]
        if (!$actor) return Decision::deny('unauthenticated', 401);

        $tenant = $this->tenant->fromContext($r);          // [2a] tenant มาจาก server-side เท่านั้น (ADR-013)
        $ws = $this->workspace->resolveOrFail($r, $res, $tenant); // [2b] 404 ถ้าไม่ใช่ของ actor
        $rel = $this->relationship->resolve($actor, $res); // [3] role/relationship

        $perm = $this->catalog->get($permission);          // จาก PERMISSION_CATALOG เท่านั้น
        if (!$perm) return Decision::deny('unknown_permission', 500); // bug ไม่ใช่ 403

        if (!$this->grants->allows($actor, $res, $perm, $rel)) // [4]
            return Decision::deny('out_of_scope', 403);

        if ($p && !$this->consent->satisfied($res->profileId, $p)) // [5]
            return Decision::deny('no_consent', 403);

        if (!$this->domainRules->passes($permission, $res))       // [6]
            return Decision::deny('domain_rule', 409);

        $this->audit->record($actor, $permission, $res);          // [7]
        return Decision::allow();
    }
}
```

**Fail-closed:** ถ้าชั้นใด throw/timeout/ข้อมูลไม่ครบ → `Decision::deny()` และ **ห้าม retry แบบข้ามการตรวจ** (F-09)
ถ้าไม่มี tenant หรือ workspace context → deny ทันที ห้ามพยายามเดา (ADR-013)

---

## 3. HTTP status policy

| สถานการณ์ | status | เหตุผล |
|---|---|---|
| ไม่ได้ยืนยันตัวตน / token หมดอายุ | `401` | |
| resource อยู่นอก workspace/scope ของผู้ใช้ | **`404`** | ไม่เปิดเผยว่าข้อมูลมีอยู่ (กัน enumeration) |
| อยู่ใน scope แต่ไม่มี permission | `403` | |
| domain rule ไม่ผ่าน (เช่น complete งานที่ไม่ได้ assigned) | `409` | |
| validation | `422` | |
| ระบบตรวจสิทธิ์ล่ม | `503` + ไม่เปิดเผยข้อมูล | F-09 |

> เลือกนโยบายเดียวใช้ทั้งระบบ — ห้ามบาง endpoint คืน 403 บางที่คืน 404 สำหรับเคสเดียวกัน

---

## 4. Role ↔ Persona ↔ baseline

baseline มี 8 role ตายตัว:

| baseline role | Persona ที่ใช้ | ขอบเขต |
|---|---|---|
| `profile_owner` | P1/P2 (เจ้าของข้อมูล) | ข้อมูลของตน + มอบสิทธิ์ในขอบเขตที่ตนมี |
| `legal_guardian` | ผู้แทนของ P3 | ข้อมูลของผู้อยู่ในความดูแล **หลังยืนยันอำนาจ** — L2 ขึ้นไปตาม [ADR-015](DECISIONS.md#adr-015--หลักฐานอำนาจผู้แทน-3-ระดับตามความเสี่ยง) |
| `workspace_admin` | P1 | สมาชิก/คำเชิญ/การตั้งค่า — **ไม่ใช่สิทธิ์สุขภาพ** |
| `caregiver` | P2/P4 | ตาม access grant เท่านั้น |
| `viewer` | ญาติที่ดูอย่างเดียว | อ่าน subset ที่อนุญาต |
| `healthcare_professional` | P4 | ตามความสัมพันธ์การรักษา + วัตถุประสงค์ |
| `platform_support` | staff | ไม่มีสิทธิ์สุขภาพเป็นค่าเริ่มต้น (F-10) |
| `platform_admin` | staff | จัดการระบบ ไม่ได้ข้อมูลสุขภาพอัตโนมัติ |

**คำที่เอกสารเดิมใช้และ mapping:**

| คำในเอกสารเดิม | เทียบกับ baseline |
|---|---|
| "Family Admin" (SUMMARY.md §4.2) | `workspace_admin` |
| "Parent / Legal Guardian" | `legal_guardian` |
| "Workspace Owner" (SUMMARY_2 §4) | `workspace_admin` + ownership flag (ไม่ใช่ role ใหม่) |
| "Task Assignee" (SUMMARY_2 §4) | **ไม่ใช่ role** — เป็นความสัมพันธ์ผ่าน `task_assignments` + `care_task.*` permission |
| "Care Coordinator" / "Primary Caregiver" (PDF1/PDF2) | persona ใน UI, ในระบบคือ `caregiver` + grant ที่กว้างกว่า |
| "Delegated Helper" (PDF2 §5) | `caregiver` ที่มี grant เฉพาะ `task_id` |
| "Dependent / Passive Member" (PDF1) | `persons` ไม่มี `user_id` + `legal_guardian` (ADR-009) |

> **ห้ามสร้าง role ใหม่ถ้า mapping นี้ครอบได้** — การเพิ่ม role ต้องแก้ baseline + matrix + test ทั้งชุด (ADR-009, ADR-012)

---

## 5. Scope model

`access_grants.resource_scope` เป็นชุดของ scope token (JSONB array) ตามหมวดข้อมูล:

| scope | ครอบคลุม |
|---|---|
| `profile.basic` | ชื่อ, วันเกิด, กลุ่มเลือด, โรคประจำตัว, แพ้ยา |
| `vitals` | `health_measurements` |
| `medications` | `medication_plans`, `medication_records` |
| `appointments` | `appointments` |
| `documents` | `documents` (metadata + ไฟล์) |
| `tasks` | `care_tasks` ที่เกี่ยวกับ profile นี้ |
| `mental_health` | ข้อมูลสุขภาพจิต — **reserved, ปิดใน MVP** ([ADR-014](DECISIONS.md#adr-014--sensitive-scopes-ปิดใน-mvp-mental_health-lab_results)) |
| `lab_results` | ผลแล็บ (structured) — **reserved, ปิดใน MVP**; เก็บใบแล็บเป็น `documents` แทน |
| `timeline` | `timeline_entries` (กรองตาม scope อื่นอีกชั้น) |
| `ai_context` | อนุญาตให้ดึงเข้าประมวลผล AI |

**กฎเหล็ก:** `timeline`, `tasks`, `documents` **ไม่ใช่**ประตูข้าม scope — การเปิดรายละเอียดต้องตรวจ scope ของข้อมูลต้นทางอีกครั้ง (F-06, DM-007)

**ประกาศ ≠ เปิดใช้:** scope ที่อยู่ในตารางนี้คือ *คำศัพท์* ที่ระบบเข้าใจ ไม่ได้แปลว่าทุกตัวเปิดใช้งานแล้ว
`mental_health` และ `lab_results` ถูกประกาศแต่ **ปิดใน MVP** — การขอเข้าถึงต้องได้ `403` และการสร้าง grant ที่ระบุ scope เหล่านี้ต้องได้ `422` (ADR-014)

---

## 6. จุดบังคับใช้ (Enforcement points)

สิทธิ์ต้องถูกตรวจที่ **ทุกจุด** ต่อไปนี้ ไม่ใช่แค่ controller:

| # | จุด | ตรวจอะไร |
|---|---|---|
| E1 | HTTP middleware | authentication + workspace resolution |
| E2 | FormRequest | input ที่อาจเปลี่ยน scope (`profile_id`, `workspace_id`) |
| E3 | Policy/Gate | permission + scope + consent |
| E4 | Domain service | domain rules (state) |
| E5 | Query scope | `where workspace_id = ...` + RLS (§ADR-007) |
| E6 | **Queue job** | ตรวจสิทธิ์ซ้ำ ณ เวลาทำงาน (F-17) |
| E7 | **File delivery** | signed URL ต้องตรวจสิทธิ์ตอนกด + มีอายุ ≤15 นาที |
| E8 | Export/Report | ตรวจ export permission + นับเฉพาะแถวที่ผู้ขอเห็น |
| E9 | AI gateway | ตรวจ permission + consent ก่อนดึงข้อมูล (F-06) |
| E10 | Notification payload | scrub ตาม N-02 |

---

## 7. Cache และการเพิกถอน (F-08)

```
key:   authz:{tenant_id}:{user_id}:{profile_id}:{scope_hash}
value: decision + expires_at
ttl:   ≤ 60 วินาที (ปกติ 30)
```

- เพิกถอน grant/consent → ยิง event → `DEL` **ทุก** key ที่เกี่ยวกับ `(grantee, profile)` ทันที (ไม่รอ TTL)
- Token ที่ยังไม่หมดอายุ **ไม่ใช่เหตุผลให้ผ่าน** — ตรวจ `access_grants.status` และ `expires_at` ทุกครั้งที่ cache miss
- งานในคิวที่สร้างก่อนเพิกถอน → ต้อง fail ตอน execute (F-17, test `SEC-T05`)

---

## 8. วิธีเพิ่ม endpoint ใหม่ (checklist บังคับ)

1. ระบุ **permission ID** จาก catalog ที่มีอยู่ — ถ้าไม่มี → ADR-012 delta (ห้ามคิดเอง)
2. ระบุ **scope** ที่ต้องมี
3. ระบุ **consent purpose** ถ้าเป็นการประมวลผล (เช่น AI, การตลาด)
4. ประกาศ route ใต้ `/workspaces/{workspace}/...` (ADR-005)
5. เขียน Policy + test: **positive, negative, cross-workspace, expired grant** อย่างน้อย 4 เคส
6. เพิ่มใน [`10-TESTING.md`](10-TESTING.md) matrix

**ถ้าข้อใดข้อหนึ่งทำไม่ได้ → endpoint ยังไม่พร้อม merge**
