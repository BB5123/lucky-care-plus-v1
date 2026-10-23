/**
 * Typed mirror of the care-task API (`.../workspaces/{workspace}/care-tasks`).
 *
 * Two shapes exist on purpose (AS-004 / CRM-006):
 *  - `care_task` cards for an assignee without a profile grant: title, due,
 *    status, priority, profile display name and version only;
 *  - the full DTO for a reader who owns the profile or holds a `tasks`/`vitals`
 *    grant.
 *
 * The parser keeps the two apart so a template cannot accidentally read
 * `description` or evidence from a safe card. The state machine below mirrors
 * `CareTaskStateMachine`; it only *offers* actions. The server remains the
 * authority and a 409 is rendered as the domain sentence.
 */

export const CARE_TASK_TIERS = ['routine', 'delegated', 'clinical'] as const;

export type CareTaskTier = (typeof CARE_TASK_TIERS)[number];

export const CARE_TASK_STATUSES = [
  'scheduled',
  'open',
  'assigned',
  'accepted',
  'in_progress',
  'blocked',
  'completed',
  'cancelled',
] as const;

export type CareTaskStatus = (typeof CARE_TASK_STATUSES)[number];

export const CARE_TASK_OUTCOMES = ['done', 'skipped', 'missed', 'failed'] as const;

export type CareTaskOutcome = (typeof CARE_TASK_OUTCOMES)[number];

export const CARE_TASK_PRIORITIES = ['low', 'normal', 'high'] as const;

export type CareTaskPriority = (typeof CARE_TASK_PRIORITIES)[number];

export const CARE_TASK_TYPES = [
  'MEDICATION_SUPPORT',
  'VITAL_MEASUREMENT',
  'APPOINTMENT',
  'DOCUMENT_FOLLOW_UP',
  'DAILY_CARE',
  'FOLLOW_UP',
  'CUSTOM',
] as const;

export const CARE_TASK_VERIFICATIONS = ['not_required', 'pending', 'verified', 'rejected'] as const;

export const EVIDENCE_POLICIES = ['none', 'value', 'photo', 'both'] as const;

export type EvidencePolicy = (typeof EVIDENCE_POLICIES)[number];

export const EVIDENCE_TYPES = ['value', 'photo', 'note'] as const;

export type EvidenceType = (typeof EVIDENCE_TYPES)[number];

export const TASK_ACTION_NAMES = [
  'assign',
  'accept',
  'decline',
  'start',
  'complete',
  'verify',
  'reject',
  'reopen',
  'cancel',
  'block',
] as const;

export type TaskAction = (typeof TASK_ACTION_NAMES)[number];

export type TaskGroupKey = 'overdue' | 'today' | 'later';

export interface TaskAssignment {
  readonly id: string;
  readonly user_id: number;
  readonly role: string;
  readonly can_complete: boolean;
  readonly accepted_at: string | null;
  readonly declined_at: string | null;
  readonly released_at: string | null;
}

export interface CareTaskBase {
  readonly id: string;
  readonly type: string;
  readonly title: string;
  readonly due_at: string | null;
  readonly status: string;
  readonly priority: string;
  readonly profile_display_name: string | null;
  readonly version: number;
}

export interface CareTaskCard extends CareTaskBase {
  readonly kind: 'card';
}

export interface CareTaskFull extends CareTaskBase {
  readonly kind: 'full';
  readonly profile_id: string;
  readonly tier: string;
  readonly description: string | null;
  readonly scheduled_for: string | null;
  readonly timezone: string | null;
  readonly outcome: string | null;
  readonly verification: string;
  readonly verification_required: boolean;
  readonly evidence_policy: string;
  readonly escalation_level: number;
  readonly blocked_reason: string | null;
  readonly review_at: string | null;
  readonly completed_at: string | null;
  readonly source: string;
  readonly created_by: string | number | null;
  readonly assignments: readonly TaskAssignment[];
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export type CareTask = CareTaskCard | CareTaskFull;

export interface TaskEvidence {
  readonly id: string;
  readonly type: string;
  readonly metric: string | null;
  readonly value: number | null;
  readonly unit: string | null;
  readonly document_id: string | null;
  readonly note: string | null;
  readonly created_by: string | number | null;
  readonly created_at: string | null;
}

export interface TaskEvent {
  readonly id: string;
  readonly actor_id: number | null;
  readonly event: string;
  readonly from_status: string | null;
  readonly to_status: string | null;
  readonly reason: string | null;
  readonly occurred_at: string | null;
}

export interface CursorPage<T> {
  readonly items: readonly T[];
  readonly nextCursor: string | null;
  readonly limit: number;
}

export interface CareTaskFilters {
  readonly status?: CareTaskStatus | null;
  readonly tier?: CareTaskTier | null;
  readonly assignee?: 'me' | null;
  readonly overdue?: boolean;
  readonly limit?: number;
  readonly cursor?: string | null;
}

export interface TaskGroup {
  readonly key: TaskGroupKey;
  readonly label: string;
  readonly tasks: readonly CareTask[];
}

export interface TaskActionContext {
  readonly currentUserId: string | null;
  /** The actor who completed the task, when the events were readable. */
  readonly completedByUserId: string | null;
}

export interface EvidenceDraft {
  readonly type: string;
  readonly metric: string;
  readonly value: string | number | null;
  readonly unit: string;
  readonly note: string;
}

export interface EvidenceWriteBody {
  readonly type: EvidenceType;
  readonly metric?: string;
  readonly value?: number;
  readonly unit?: string;
  readonly note?: string;
}

export interface EvidenceMetricOption {
  readonly value: string;
  readonly label: string;
  readonly unit: string;
}

export const EVIDENCE_METRICS: readonly EvidenceMetricOption[] = [
  { value: 'blood_pressure_systolic', label: 'ความดันตัวบน', unit: 'mmHg' },
  { value: 'blood_pressure_diastolic', label: 'ความดันตัวล่าง', unit: 'mmHg' },
  { value: 'blood_glucose', label: 'น้ําตาลในเลือด', unit: 'mg/dL' },
  { value: 'heart_rate', label: 'ชีพจร', unit: 'bpm' },
  { value: 'weight', label: 'น้ําหนัก', unit: 'kg' },
  { value: 'spo2', label: 'ออกซิเจนในเลือด', unit: '%' },
  { value: 'temperature', label: 'อุณหภูมิ', unit: '°C' },
];

const EMPTY_FIELD_ERRORS: Readonly<Record<string, string>> = {};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function readObject(value: unknown): Record<string, unknown> {
  return isRecord(value) ? value : {};
}

function readArray(value: unknown): readonly unknown[] {
  return Array.isArray(value) ? value : [];
}

function readString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

function readNumber(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function readBoolean(value: unknown): boolean {
  return value === true;
}

function readUserId(value: unknown): string | number | null {
  if (typeof value === 'string') {
    return value;
  }

  const numeric = readNumber(value);

  return numeric === null ? null : numeric;
}

export function isCareTaskTier(value: string): value is CareTaskTier {
  return (CARE_TASK_TIERS as readonly string[]).includes(value);
}

export function isCareTaskStatus(value: string): value is CareTaskStatus {
  return (CARE_TASK_STATUSES as readonly string[]).includes(value);
}

export function isEvidencePolicy(value: string): value is EvidencePolicy {
  return (EVIDENCE_POLICIES as readonly string[]).includes(value);
}

export function isEvidenceType(value: string): value is EvidenceType {
  return (EVIDENCE_TYPES as readonly string[]).includes(value);
}

export function taskTierLabel(tier: string): string {
  switch (tier) {
    case 'routine':
      return 'งานกิจวัตร';
    case 'delegated':
      return 'งานที่มอบหมาย';
    case 'clinical':
      return 'งานทางคลินิก';
    default:
      return 'งานดูแล';
  }
}

export function taskStatusLabel(status: string): string {
  switch (status) {
    case 'scheduled':
      return 'รอดําเนินการ';
    case 'open':
      return 'รอผู้รับผิดชอบ';
    case 'assigned':
      return 'มอบหมายแล้ว';
    case 'accepted':
      return 'รับงานแล้ว';
    case 'in_progress':
      return 'กําลังดําเนินการ';
    case 'blocked':
      return 'ติดขัด';
    case 'completed':
      return 'เสร็จแล้ว';
    case 'cancelled':
      return 'ยกเลิก';
    default:
      return 'ไม่ทราบสถานะ';
  }
}

export function taskPriorityLabel(priority: string): string {
  switch (priority) {
    case 'low':
      return 'ต่ํา';
    case 'high':
      return 'สูง';
    default:
      return 'ปกติ';
  }
}

export function taskTypeLabel(type: string): string {
  switch (type) {
    case 'MEDICATION_SUPPORT':
      return 'ช่วยเรื่องยา';
    case 'VITAL_MEASUREMENT':
      return 'วัดค่าสุขภาพ';
    case 'APPOINTMENT':
      return 'นัดหมาย';
    case 'DOCUMENT_FOLLOW_UP':
      return 'ติดตามเอกสาร';
    case 'DAILY_CARE':
      return 'ดูแลประจําวัน';
    case 'FOLLOW_UP':
      return 'ติดตามผล';
    default:
      return 'งานทั่วไป';
  }
}

export function taskOutcomeLabel(outcome: string | null): string {
  switch (outcome) {
    case 'done':
      return 'ทําเสร็จ';
    case 'skipped':
      return 'ข้าม';
    case 'missed':
      return 'พลาด';
    case 'failed':
      return 'ไม่สําเร็จ';
    default:
      return 'ยังไม่ระบุผล';
  }
}

export function verificationLabel(verification: string): string {
  switch (verification) {
    case 'pending':
      return 'รอตรวจรับ';
    case 'verified':
      return 'ตรวจรับแล้ว';
    case 'rejected':
      return 'ถูกตีกลับ';
    default:
      return 'ไม่ต้องตรวจรับ';
  }
}

export function evidencePolicyLabel(policy: string): string {
  switch (policy) {
    case 'value':
      return 'ต้องแนบค่าที่วัด';
    case 'photo':
      return 'ต้องแนบภาพถ่าย';
    case 'both':
      return 'ต้องแนบค่าที่วัดและภาพถ่าย';
    default:
      return 'ไม่ต้องแนบหลักฐาน';
  }
}

export function taskActionLabel(action: TaskAction): string {
  switch (action) {
    case 'accept':
      return 'รับงาน';
    case 'decline':
      return 'ปฏิเสธงาน';
    case 'start':
      return 'เริ่มทํางาน';
    case 'complete':
      return 'ปิดงาน';
    case 'verify':
      return 'ตรวจรับ';
    case 'reject':
      return 'ตีกลับ';
    case 'reopen':
      return 'เปิดงานใหม่';
    case 'cancel':
      return 'ยกเลิกงาน';
    case 'block':
      return 'แจ้งติดขัด';
    default:
      return 'มอบหมาย';
  }
}

export function parseTaskAssignment(value: unknown): TaskAssignment | null {
  const raw = readObject(value);
  const id = readString(raw['id']);
  const userId = readNumber(raw['user_id']);

  if (id === null || id === '' || userId === null) {
    return null;
  }

  return {
    id,
    user_id: userId,
    role: readString(raw['role']) ?? 'assistant',
    can_complete: readBoolean(raw['can_complete']),
    accepted_at: readString(raw['accepted_at']),
    declined_at: readString(raw['declined_at']),
    released_at: readString(raw['released_at']),
  };
}

/**
 * A payload is a full task only when it carries both `tier` and `profile_id`.
 * Everything else is the need-to-know card, which never has those fields.
 */
export function parseCareTask(value: unknown): CareTask | null {
  const raw = readObject(value);
  const id = readString(raw['id']);
  const title = readString(raw['title']);

  if (id === null || id === '' || title === null) {
    return null;
  }

  const base: CareTaskBase = {
    id,
    type: readString(raw['type']) ?? 'CUSTOM',
    title,
    due_at: readString(raw['due_at']),
    status: readString(raw['status']) ?? 'open',
    priority: readString(raw['priority']) ?? 'normal',
    profile_display_name: readString(raw['profile_display_name']),
    version: readNumber(raw['version']) ?? 0,
  };

  const tier = readString(raw['tier']);
  const profileId = readString(raw['profile_id']);

  if (tier === null || !isCareTaskTier(tier) || profileId === null || profileId === '') {
    return { ...base, kind: 'card' };
  }

  return {
    ...base,
    kind: 'full',
    profile_id: profileId,
    tier,
    description: readString(raw['description']),
    scheduled_for: readString(raw['scheduled_for']),
    timezone: readString(raw['timezone']),
    outcome: readString(raw['outcome']),
    verification: readString(raw['verification']) ?? 'not_required',
    verification_required: readBoolean(raw['verification_required']),
    evidence_policy: readString(raw['evidence_policy']) ?? 'none',
    escalation_level: readNumber(raw['escalation_level']) ?? 0,
    blocked_reason: readString(raw['blocked_reason']),
    review_at: readString(raw['review_at']),
    completed_at: readString(raw['completed_at']),
    source: readString(raw['source']) ?? 'manual',
    created_by: readUserId(raw['created_by']),
    assignments: readArray(raw['assignments'])
      .map((entry) => parseTaskAssignment(entry))
      .filter((entry): entry is TaskAssignment => entry !== null),
    created_at: readString(raw['created_at']),
    updated_at: readString(raw['updated_at']),
  };
}

export function parseCareTaskPage(value: unknown): CursorPage<CareTask> {
  const raw = readObject(value);
  const meta = readObject(raw['meta']);
  const items = readArray(raw['data'])
    .map((item) => parseCareTask(item))
    .filter((item): item is CareTask => item !== null);

  return {
    items,
    nextCursor: readString(meta['next_cursor']),
    limit: readNumber(meta['limit']) ?? 20,
  };
}

export function parseTaskEvidence(value: unknown): TaskEvidence | null {
  const raw = readObject(value);
  const id = readString(raw['id']);

  if (id === null || id === '') {
    return null;
  }

  return {
    id,
    type: readString(raw['type']) ?? 'note',
    metric: readString(raw['metric']),
    value: readNumber(raw['value']),
    unit: readString(raw['unit']),
    document_id: readString(raw['document_id']),
    note: readString(raw['note']),
    created_by: readUserId(raw['created_by']),
    created_at: readString(raw['created_at']),
  };
}

export function parseTaskEvidenceList(value: unknown): readonly TaskEvidence[] {
  return readArray(value)
    .map((entry) => parseTaskEvidence(entry))
    .filter((entry): entry is TaskEvidence => entry !== null);
}

export function parseTaskEventList(value: unknown): readonly TaskEvent[] {
  return readArray(value)
    .map((entry): TaskEvent | null => {
      const raw = readObject(entry);
      const id = readString(raw['id']);

      if (id === null || id === '') {
        return null;
      }

      return {
        id,
        actor_id: readNumber(raw['actor_id']),
        event: readString(raw['event']) ?? '',
        from_status: readString(raw['from_status']),
        to_status: readString(raw['to_status']),
        reason: readString(raw['reason']),
        occurred_at: readString(raw['occurred_at']),
      };
    })
    .filter((entry): entry is TaskEvent => entry !== null);
}

function parseTimestamp(value: string | null): number | null {
  if (value === null || value === '') {
    return null;
  }

  const timestamp = Date.parse(value);

  return Number.isNaN(timestamp) ? null : timestamp;
}

function startOfLocalDay(timestamp: number): number {
  const date = new Date(timestamp);

  date.setHours(0, 0, 0, 0);

  return date.getTime();
}

export function isTaskOverdue(task: CareTask, now: Date = new Date()): boolean {
  const due = parseTimestamp(task.due_at);

  if (due === null || task.status === 'completed' || task.status === 'cancelled') {
    return false;
  }

  return due < now.getTime();
}

/**
 * Overdue first, then work due today, then everything else. The API already
 * orders by `due_at`; grouping only decides presentation.
 */
export function groupTasks(
  tasks: readonly CareTask[],
  now: Date = new Date(),
): readonly TaskGroup[] {
  const today = startOfLocalDay(now.getTime());
  const groups: Record<TaskGroupKey, CareTask[]> = {
    overdue: [],
    today: [],
    later: [],
  };

  for (const task of tasks) {
    const due = parseTimestamp(task.due_at);

    if (isTaskOverdue(task, now)) {
      groups.overdue.push(task);
    } else if (due !== null && startOfLocalDay(due) === today) {
      groups.today.push(task);
    } else {
      groups.later.push(task);
    }
  }

  return [
    { key: 'overdue', label: 'เลยกําหนด', tasks: groups.overdue },
    { key: 'today', label: 'วันนี้', tasks: groups.today },
    { key: 'later', label: 'ถัดไป', tasks: groups.later },
  ];
}

/**
 * Mirrors `CareTaskStateMachine` for presentation only. It never sends an
 * action by itself; the server re-checks every transition and answers 409.
 */
export function availableActions(
  task: CareTask,
  context: TaskActionContext,
): readonly TaskAction[] {
  if (task.kind === 'card') {
    return [];
  }

  if (task.tier === 'routine') {
    // Routine work is one tap: complete with an outcome. Cancel is the only
    // other transition the blueprint allows from `scheduled`.
    if (task.status === 'scheduled') {
      return ['complete', 'cancel'];
    }

    return [];
  }

  if (task.tier === 'delegated') {
    switch (task.status) {
      case 'open':
        return ['cancel'];
      case 'assigned':
        return ['accept', 'decline', 'block', 'cancel'];
      case 'accepted':
        return ['start', 'decline', 'block', 'cancel'];
      case 'in_progress':
        return ['complete', 'block', 'cancel'];
      case 'blocked':
        return ['start', 'cancel'];
      case 'completed':
        return ['reopen'];
      default:
        return [];
    }
  }

  if (task.tier === 'clinical') {
    switch (task.status) {
      case 'open':
        return ['cancel'];
      case 'assigned':
        return ['start', 'cancel'];
      case 'in_progress':
        return ['complete', 'cancel'];
      case 'completed':
        if (task.verification === 'pending') {
          // CT-003: the completer cannot verify their own work. If the events
          // were unreadable we still offer the action; the server enforces it.
          return canVerify(task, context) ? ['verify', 'reject'] : [];
        }

        return task.verification === 'rejected' ? ['reopen'] : [];
      default:
        return [];
    }
  }

  return [];
}

function canVerify(task: CareTaskFull, context: TaskActionContext): boolean {
  if (context.completedByUserId === null || context.currentUserId === null) {
    return true;
  }

  return context.completedByUserId !== context.currentUserId;
}

export function actionRequiresReason(action: TaskAction): boolean {
  return ['decline', 'reject', 'reopen', 'cancel'].includes(action);
}

export function actionRequiresReviewAt(action: TaskAction): boolean {
  return action === 'block';
}

/**
 * Evidence required before a clinical completion can be submitted. The same
 * rule lives in `CareTaskTransitionService::assertRequiredEvidence()`; this is
 * only an early, disabled-button guard so the user is not sent into a 422.
 */
export function missingEvidenceMessage(
  task: CareTask,
  evidence: readonly TaskEvidence[],
): string | null {
  if (task.kind !== 'full' || task.tier !== 'clinical') {
    return null;
  }

  const policy: string = task.evidence_policy;

  if (policy === 'none' || !isEvidencePolicy(policy)) {
    return null;
  }

  const hasValue = evidence.some((item) => item.type === 'value');
  const hasPhoto = evidence.some((item) => item.type === 'photo');
  const satisfied =
    policy === 'value' ? hasValue : policy === 'photo' ? hasPhoto : hasValue && hasPhoto;

  if (satisfied) {
    return null;
  }

  if (policy === 'value') {
    return 'ต้องแนบค่าที่วัดก่อนปิดงานทางคลินิก';
  }

  if (policy === 'photo') {
    return 'ต้องแนบภาพถ่ายก่อนปิดงานทางคลินิก';
  }

  return 'ต้องแนบค่าที่วัดและภาพถ่ายก่อนปิดงานทางคลินิก';
}

export function validateEvidenceDraft(draft: EvidenceDraft): Readonly<Record<string, string>> {
  const errors: Record<string, string> = {};

  if (!isEvidenceType(draft.type)) {
    errors['type'] = 'กรุณาเลือกประเภทหลักฐาน';
    return errors;
  }

  if (draft.type === 'value') {
    if (draft.metric.trim() === '') {
      errors['metric'] = 'กรุณาเลือกค่าที่วัด';
    }

    const value =
      typeof draft.value === 'number'
        ? draft.value
        : draft.value === null || draft.value.trim() === ''
          ? null
          : Number(draft.value.trim());

    if (value === null || !Number.isFinite(value)) {
      errors['value'] = 'กรุณากรอกค่าเป็นตัวเลข';
    }
  }

  if (draft.type === 'note' && draft.note.trim() === '') {
    errors['note'] = 'กรุณากรอกบันทึกหลักฐาน';
  }

  if (draft.note.trim().length > 20000) {
    errors['note'] = 'บันทึกต้องไม่เกิน 20,000 ตัวอักษร';
  }

  return Object.keys(errors).length === 0 ? EMPTY_FIELD_ERRORS : errors;
}

export function buildEvidenceBody(draft: EvidenceDraft): EvidenceWriteBody {
  const body: {
    type: EvidenceType;
    metric?: string;
    value?: number;
    unit?: string;
    note?: string;
  } = {
    type: isEvidenceType(draft.type) ? draft.type : 'note',
  };

  if (body.type === 'value') {
    body.metric = draft.metric.trim();
    body.value = Number(draft.value);

    const unit = draft.unit.trim();

    if (unit !== '') {
      body.unit = unit;
    }
  }

  if (body.type === 'note') {
    body.note = draft.note.trim();
  }

  return body;
}

export function completionOutcomes(tier: string): readonly CareTaskOutcome[] {
  if (tier === 'routine') {
    return ['done', 'skipped'];
  }

  if (tier === 'delegated') {
    return ['done', 'skipped', 'failed'];
  }

  return ['done'];
}
