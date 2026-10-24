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

/// What the family tab needs once the workspace resolves.
class FamilyData {
  FamilyData({
    required this.hasWorkspace,
    required List<FamilyMembership> members,
  }) : members = List<FamilyMembership>.unmodifiable(members);

  const FamilyData._({required this.hasWorkspace, required this.members});

  final bool hasWorkspace;
  final List<FamilyMembership> members;

  bool get isEmpty => members.isEmpty;

  static const FamilyData noWorkspace = FamilyData._(
    hasWorkspace: false,
    members: <FamilyMembership>[],
  );
}
