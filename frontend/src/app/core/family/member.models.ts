/**
 * Typed mirror of `GET /workspaces/{workspace}/members` and
 * `POST /workspaces/{workspace}/invitations`.
 *
 * The member payload is parsed defensively: a row without an identity is
 * dropped, and a missing or unknown status becomes `unknown` — never `active`.
 * Claiming "active" for an absent field would hide a suspended membership.
 */

/** Roles the invitation API accepts (mirrors `InvitationService::INVITABLE_ROLES`). */
export const INVITABLE_ROLES = [
  'workspace_admin',
  'caregiver',
  'healthcare_professional',
  'legal_guardian',
  'viewer',
] as const;

export type InvitableRole = (typeof INVITABLE_ROLES)[number];

const MEMBER_STATUSES = [
  'active',
  'suspended',
  'invited',
  'pending',
  'inactive',
  'revoked',
] as const;

export type MemberStatus = (typeof MEMBER_STATUSES)[number] | 'unknown';

export interface WorkspaceMember {
  readonly id: number;
  readonly user_id: number;
  readonly membership_id: string | null;
  readonly name: string | null;
  readonly role: string;
  readonly status: MemberStatus;
}

/** The one-time response of a successful invitation. */
export interface InvitationResult {
  readonly id: string;
  readonly email: string;
  readonly role: string;
  readonly expires_at: string | null;
  readonly token: string;
}

export interface RoleDefinition {
  readonly role: string;
  readonly label: string;
  readonly description: string;
}

export interface InvitableRoleDefinition {
  readonly role: InvitableRole;
  readonly label: string;
}

const ROLE_RANK: Readonly<Record<string, number>> = {
  profile_owner: 0,
  workspace_admin: 1,
  legal_guardian: 2,
  caregiver: 3,
  viewer: 4,
  healthcare_professional: 5,
  platform_support: 6,
  platform_admin: 7,
  unknown: 99,
};

const ROLE_LABELS: Readonly<Record<string, string>> = {
  profile_owner: 'เจ้าของข้อมูล',
  legal_guardian: 'ผู้แทนโดยชอบธรรม',
  workspace_admin: 'ผู้ดูแลครอบครัว',
  caregiver: 'ผู้ดูแล',
  viewer: 'ผู้ชม',
  healthcare_professional: 'บุคลากรทางการแพทย์',
  platform_support: 'ฝ่ายสนับสนุน',
  platform_admin: 'ผู้ดูแลระบบ',
  unknown: 'ไม่ทราบบทบาท',
};

/** Owner, guardian, admin, caregiver and viewer in the blueprint's order. */
export const ROLE_DEFINITIONS: readonly RoleDefinition[] = [
  {
    role: 'profile_owner',
    label: ROLE_LABELS['profile_owner'] ?? 'เจ้าของข้อมูล',
    description: 'เข้าถึงข้อมูลสุขภาพของตนเอง และมอบสิทธิ์ให้ผู้อื่นได้ตามขอบเขตที่กำหนด',
  },
  {
    role: 'legal_guardian',
    label: ROLE_LABELS['legal_guardian'] ?? 'ผู้แทนโดยชอบธรรม',
    description:
      'เข้าถึงข้อมูลของผู้อยู่ในความดูแลหลังยืนยันอำนาจ และจัดการสมาชิกได้ตามที่ได้รับมอบหมาย',
  },
  {
    role: 'workspace_admin',
    label: ROLE_LABELS['workspace_admin'] ?? 'ผู้ดูแลครอบครัว',
    description:
      'จัดการสมาชิก คำเชิญ และการตั้งค่าครอบครัว แต่ไม่มีสิทธิ์เข้าถึงข้อมูลสุขภาพโดยอัตโนมัติ',
  },
  {
    role: 'caregiver',
    label: ROLE_LABELS['caregiver'] ?? 'ผู้ดูแล',
    description: 'ดูแลงานที่ได้รับมอบหมาย และเข้าถึงข้อมูลสุขภาพตามสิทธิ์ที่ได้รับเท่านั้น',
  },
  {
    role: 'viewer',
    label: ROLE_LABELS['viewer'] ?? 'ผู้ชม',
    description: 'อ่านข้อมูลบางส่วนที่ได้รับอนุญาตได้ แต่แก้ไขข้อมูลหรือจัดการสมาชิกไม่ได้',
  },
];

/** The same order as the API constant, so the select can never drift. */
export const INVITABLE_ROLE_DEFINITIONS: readonly InvitableRoleDefinition[] = [
  { role: 'workspace_admin', label: ROLE_LABELS['workspace_admin'] ?? 'ผู้ดูแลครอบครัว' },
  { role: 'caregiver', label: ROLE_LABELS['caregiver'] ?? 'ผู้ดูแล' },
  {
    role: 'healthcare_professional',
    label: ROLE_LABELS['healthcare_professional'] ?? 'บุคลากรทางการแพทย์',
  },
  { role: 'legal_guardian', label: ROLE_LABELS['legal_guardian'] ?? 'ผู้แทนโดยชอบธรรม' },
  { role: 'viewer', label: ROLE_LABELS['viewer'] ?? 'ผู้ชม' },
];

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const NAME_COLLATOR = new Intl.Collator('th');

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function readString(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  const trimmed = value.trim();

  return trimmed === '' ? null : trimmed;
}

function readId(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }

  // The API sends a bigint id as a number, but a string is accepted when it is
  // a plain integer so a form-encoded or future payload cannot be dropped.
  if (typeof value === 'string' && /^\d+$/.test(value.trim())) {
    return Number(value.trim());
  }

  return null;
}

function readStatus(value: unknown): MemberStatus {
  const status = readString(value)?.toLowerCase() ?? null;

  if (status === null) {
    return 'unknown';
  }

  return (MEMBER_STATUSES as readonly string[]).includes(status)
    ? (status as MemberStatus)
    : 'unknown';
}

function roleRank(role: string): number {
  return ROLE_RANK[role] ?? ROLE_RANK['unknown'] ?? 99;
}

export function parseMember(value: unknown): WorkspaceMember | null {
  if (!isRecord(value)) {
    return null;
  }

  const userId = readId(value['user_id']) ?? readId(value['id']);

  if (userId === null) {
    return null;
  }

  return {
    id: readId(value['id']) ?? userId,
    user_id: userId,
    membership_id: readString(value['membership_id']),
    name: readString(value['name']),
    role: readString(value['role']) ?? 'unknown',
    status: readStatus(value['status']),
  };
}

/** Owner first, then admin, guardian, caregiver, viewer, then by name. */
export function sortMembers(members: readonly WorkspaceMember[]): WorkspaceMember[] {
  return [...members].sort((left, right) => {
    const byRole = roleRank(left.role) - roleRank(right.role);

    if (byRole !== 0) {
      return byRole;
    }

    const byName = NAME_COLLATOR.compare(left.name ?? '', right.name ?? '');

    if (byName !== 0) {
      return byName;
    }

    return String(left.user_id).localeCompare(String(right.user_id));
  });
}

export function parseMembers(value: unknown): WorkspaceMember[] {
  if (!Array.isArray(value)) {
    return [];
  }

  const members = value
    .map((row) => parseMember(row))
    .filter((row): row is WorkspaceMember => row !== null);

  return sortMembers(members);
}

export function parseInvitation(value: unknown): InvitationResult | null {
  if (!isRecord(value)) {
    return null;
  }

  const token = readString(value['token']);
  const invitationId = readId(value['id']);

  // The token is the only copy of the secret. A response without it cannot be
  // rendered as a usable invitation, so it is treated as an invalid payload.
  if (token === null) {
    return null;
  }

  return {
    id: invitationId === null ? '' : String(invitationId),
    email: readString(value['email']) ?? '',
    role: readString(value['role']) ?? 'unknown',
    expires_at: readString(value['expires_at']),
    token,
  };
}

export function roleLabel(role: string): string {
  return ROLE_LABELS[role] ?? ROLE_LABELS['unknown'] ?? 'ไม่ทราบบทบาท';
}

export function statusLabel(status: string): string {
  switch (status) {
    case 'active':
      return 'ใช้งานอยู่';
    case 'suspended':
      return 'ระงับการใช้งาน';
    case 'invited':
    case 'pending':
      return 'รอตอบรับ';
    case 'inactive':
      return 'ไม่ได้ใช้งาน';
    case 'revoked':
      return 'สิ้นสุดสิทธิ์';
    default:
      return 'ไม่ทราบสถานะ';
  }
}

export function isValidEmail(value: string): boolean {
  const trimmed = value.trim();

  return trimmed.length > 0 && trimmed.length <= 255 && EMAIL_PATTERN.test(trimmed);
}

/**
 * The link handed to the invitee. The token stays in the URL only; callers
 * must not persist it in storage or a long-lived service signal.
 */
export function buildInviteLink(origin: string, token: string): string {
  const base = origin.endsWith('/') ? origin.slice(0, -1) : origin;

  return `${base}/invitations/${encodeURIComponent(token)}`;
}
