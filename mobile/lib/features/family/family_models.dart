import '../../core/config/app_config.dart';
import '../../core/format/thai_date.dart';
import '../../core/network/json_read.dart';

String familyRoleLabel(String role) {
  return switch (role) {
    'profile_owner' => 'เจ้าของโปรไฟล์',
    'legal_guardian' => 'ผู้แทนตามกฎหมาย',
    'workspace_admin' => 'ผู้ดูแลครอบครัว',
    'caregiver' => 'ผู้ดูแล',
    'viewer' => 'ผู้ชม',
    'healthcare_professional' => 'บุคลากรทางการแพทย์',
    'platform_support' => 'ทีมสนับสนุน',
    'platform_admin' => 'ผู้ดูแลระบบ',
    _ => 'สมาชิก',
  };
}

String familyStatusLabel(String status) {
  return switch (status) {
    'active' => 'ใช้งานอยู่',
    'invited' => 'รอตอบรับ',
    'pending' => 'รอดำเนินการ',
    'suspended' => 'ระงับชั่วคราว',
    'removed' => 'นำออกแล้ว',
    'revoked' => 'เพิกถอนแล้ว',
    _ => 'ไม่ทราบสถานะ',
  };
}

/// One role the invitation API accepts, with the Thai label and the short
/// explanation shown under the picker.
///
/// The values mirror `InvitationService::INVITABLE_ROLES`; the order is the
/// API's order so the client can never offer a role the server rejects.
/// Descriptions speak about what the person can see. They deliberately contain
/// no permission IDs — those are an internal vocabulary.
class InvitableRoleOption {
  const InvitableRoleOption({
    required this.role,
    required this.label,
    required this.description,
  });

  final String role;
  final String label;
  final String description;
}

const List<InvitableRoleOption> invitableRoleOptions = <InvitableRoleOption>[
  InvitableRoleOption(
    role: 'workspace_admin',
    label: 'ผู้ดูแลครอบครัว',
    description:
        'จัดการสมาชิกและคำเชิญของครอบครัวได้ '
        'แต่ไม่เห็นข้อมูลสุขภาพโดยอัตโนมัติ',
  ),
  InvitableRoleOption(
    role: 'caregiver',
    label: 'ผู้ดูแล',
    description:
        'ดูแลงานที่ได้รับมอบหมาย และเห็นข้อมูลสุขภาพตามสิทธิ์ที่ได้รับเท่านั้น',
  ),
  InvitableRoleOption(
    role: 'healthcare_professional',
    label: 'บุคลากรทางการแพทย์',
    description: 'ดูข้อมูลสุขภาพของผู้รับบริการตามที่ได้รับมอบหมาย',
  ),
  InvitableRoleOption(
    role: 'legal_guardian',
    label: 'ผู้แทนตามกฎหมาย',
    description:
        'ดูแลข้อมูลแทนสมาชิกในความดูแล หลังการยืนยันอำนาจตามกฎหมาย',
  ),
  InvitableRoleOption(
    role: 'viewer',
    label: 'ผู้ชม',
    description:
        'อ่านข้อมูลบางส่วนที่ได้รับอนุญาตได้ '
        'แต่แก้ไขข้อมูลหรือจัดการสมาชิกไม่ได้',
  ),
];

const Set<String> invitableRoleValues = <String>{
  'workspace_admin',
  'caregiver',
  'healthcare_professional',
  'legal_guardian',
  'viewer',
};

InvitableRoleOption? invitableRoleOption(String role) {
  for (final InvitableRoleOption option in invitableRoleOptions) {
    if (option.role == role) {
      return option;
    }
  }

  return null;
}

String invitableRoleLabel(String role) {
  return invitableRoleOption(role)?.label ?? familyRoleLabel(role);
}

/// Mirrors the web client's `isValidEmail`: a syntactic check before the call,
/// never a substitute for the server's validation.
bool isValidInviteEmail(String value) {
  final String trimmed = value.trim();

  if (trimmed.isEmpty || trimmed.length > 255) {
    return false;
  }

  return RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(trimmed);
}

/// The link handed to the invitee. The token stays in memory only; callers
/// must not persist it or print it.
///
/// The API base is `https://host/api/v1`, while the acceptance screen lives on
/// the web origin, so the `/api/v1` suffix is removed before appending the
/// invitation path (the same shape the web client builds from
/// `window.location.origin`).
String invitationLink(String token) {
  String base = AppConfig.apiBaseUrl;

  while (base.endsWith('/')) {
    base = base.substring(0, base.length - 1);
  }

  const String apiSuffix = '/api/v1';

  if (base.endsWith(apiSuffix)) {
    base = base.substring(0, base.length - apiSuffix.length);
  }

  return '$base/invitations/${Uri.encodeComponent(token)}';
}

/// One workspace membership from `GET /workspaces/{w}/members`.
///
/// It carries no health data: the family tab only shows who belongs to the
/// workspace and the role the server reports.
class FamilyMembership {
  const FamilyMembership({
    required this.id,
    required this.name,
    required this.role,
    required this.status,
  });

  final String id;
  final String name;
  final String role;
  final String status;

  String get roleLabel => familyRoleLabel(role);

  String get statusLabel => familyStatusLabel(status);

  factory FamilyMembership.fromJson(Map<String, dynamic> json) {
    return FamilyMembership(
      id: readString(json['membership_id'], fallback: readString(json['id'])),
      name: readString(json['name']),
      role: readString(json['role']),
      status: readString(json['status'], fallback: 'active'),
    );
  }
}

/// One invitation from `POST /workspaces/{w}/invitations` or the pending list.
///
/// [token] is present only in the create response — the server keeps a hash
/// and cannot return it again. [expiresAt] is ISO-8601; a missing value is
/// rendered as "ไม่ทราบวันหมดอายุ" instead of a made-up date.
class FamilyInvitation {
  const FamilyInvitation({
    required this.id,
    required this.email,
    required this.role,
    this.expiresAt = '',
    this.token = '',
  });

  final String id;
  final String email;
  final String role;
  final String expiresAt;
  final String token;

  String get roleLabel => invitableRoleLabel(role);

  bool get hasLink => token.isNotEmpty;

  String get link => hasLink ? invitationLink(token) : '';

  String get expiryLabel {
    final String formatted = formatThaiDateTime(expiresAt);

    return formatted.isEmpty ? 'ไม่ทราบวันหมดอายุ' : 'ลิงก์หมดอายุ $formatted';
  }

  factory FamilyInvitation.fromJson(Map<String, dynamic> json) {
    return FamilyInvitation(
      id: readString(json['id'], fallback: readString(json['invitation_id'])),
      email: readString(json['email']),
      role: readString(json['role']),
      expiresAt: readString(json['expires_at']),
      token: readString(json['token']),
    );
  }
}

/// What the family tab needs once the workspace resolves.
class FamilyData {
  FamilyData({
    required this.hasWorkspace,
    required List<FamilyMembership> members,
    List<FamilyInvitation> invitations = const <FamilyInvitation>[],
    this.canInvite = false,
  }) : members = List<FamilyMembership>.unmodifiable(members),
       invitations = List<FamilyInvitation>.unmodifiable(invitations);

  const FamilyData._({
    required this.hasWorkspace,
    required this.members,
    required this.invitations,
    required this.canInvite,
  });

  final bool hasWorkspace;

  /// Matrix A: only the owner, the legal guardian and the workspace admin may
  /// invite. A denied actor never sees the action; a direct 403 is still
  /// rendered calmly by the sheet.
  final bool canInvite;

  final List<FamilyMembership> members;
  final List<FamilyInvitation> invitations;

  bool get isEmpty => members.isEmpty;

  bool get hasInvitations => invitations.isNotEmpty;

  FamilyData withInvitations(List<FamilyInvitation> next) {
    return FamilyData(
      hasWorkspace: hasWorkspace,
      canInvite: canInvite,
      members: members,
      invitations: next,
    );
  }

  static const FamilyData noWorkspace = FamilyData._(
    hasWorkspace: false,
    members: <FamilyMembership>[],
    invitations: <FamilyInvitation>[],
    canInvite: false,
  );
}
