import '../network/api_failure.dart';
import '../network/json_read.dart';

/// The logged-in person's global identity (`Accounts` module).
class AuthUser {
  const AuthUser({
    required this.id,
    required this.name,
    required this.email,
    this.phone,
    this.phoneVerifiedAt,
  });

  final String id;
  final String name;
  final String email;
  final String? phone;
  final String? phoneVerifiedAt;

  factory AuthUser.fromJson(Map<String, dynamic> json) {
    return AuthUser(
      id: readString(json['id']),
      name: readString(json['name']),
      email: readString(json['email']),
      phone: readNullableString(json['phone']),
      phoneVerifiedAt: readNullableString(json['phone_verified_at']),
    );
  }
}

/// `data` of `POST /auth/login` and `POST /auth/register`.
class AuthSession {
  const AuthSession({required this.user, required this.token});

  final AuthUser user;
  final String token;

  factory AuthSession.fromJson(Map<String, dynamic> json) {
    final String token = readString(json['token']);

    if (token.isEmpty) {
      throw ApiFailure.protocol;
    }

    return AuthSession(
      user: AuthUser.fromJson(readMap(json['user'])),
      token: token,
    );
  }
}

/// One workspace the user belongs to, from `GET /me`.
///
/// Carries no health data; it only lets the client pick the first workspace
/// and address workspace-scoped endpoints.
class MembershipSummary {
  const MembershipSummary({
    required this.id,
    required this.workspaceId,
    required this.name,
    required this.role,
    required this.status,
  });

  final String id;
  final String workspaceId;
  final String name;
  final String role;
  final String status;

  factory MembershipSummary.fromJson(Map<String, dynamic> json) {
    final String workspaceId = readString(json['workspace_id']);
    final String id = readString(json['id']);

    return MembershipSummary(
      id: id.isNotEmpty ? id : workspaceId,
      workspaceId: workspaceId.isNotEmpty ? workspaceId : id,
      name: readString(json['name']),
      role: readString(json['role']),
      status: readString(json['status']),
    );
  }
}

/// `data` of `GET /me`.
class MeResponse {
  const MeResponse({required this.user, required this.workspaces});

  final AuthUser user;
  final List<MembershipSummary> workspaces;

  factory MeResponse.fromJson(Map<String, dynamic> json) {
    final List<MembershipSummary> workspaces = readList(json['workspaces'])
        .map((Object? entry) => MembershipSummary.fromJson(readMap(entry)))
        .where((MembershipSummary entry) => entry.workspaceId.isNotEmpty)
        .toList(growable: false);

    return MeResponse(
      user: AuthUser.fromJson(readMap(json['user'])),
      workspaces: workspaces,
    );
  }
}
