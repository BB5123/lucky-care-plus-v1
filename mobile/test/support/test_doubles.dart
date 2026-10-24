import 'package:lucky_care/core/auth/auth_models.dart';
import 'package:lucky_care/core/auth/auth_repository.dart';
import 'package:lucky_care/core/network/api_failure.dart';

/// In-memory [AuthRepository] for controller and widget tests. It counts calls
/// so a test can prove the form never reached the network (D-T05).
class FakeAuthRepository implements AuthRepository {
  FakeAuthRepository({
    this.session,
    this.loginFailure,
    this.registerFailure,
    this.meResponse,
    this.meFailure,
  });

  AuthSession? session;
  ApiFailure? loginFailure;
  ApiFailure? registerFailure;
  MeResponse? meResponse;
  ApiFailure? meFailure;

  int loginCalls = 0;
  int registerCalls = 0;
  int logoutCalls = 0;
  int meCalls = 0;

  String? lastEmail;
  String? lastPassword;
  String? lastConfirmation;

  @override
  Future<AuthSession> login({
    required String email,
    required String password,
  }) async {
    loginCalls++;
    lastEmail = email;
    lastPassword = password;

    final ApiFailure? failure = loginFailure;

    if (failure != null) {
      throw failure;
    }

    return session ?? testSession();
  }

  @override
  Future<AuthSession> register({
    required String name,
    required String email,
    required String password,
    required String confirmation,
  }) async {
    registerCalls++;
    lastEmail = email;
    lastPassword = password;
    lastConfirmation = confirmation;

    final ApiFailure? failure = registerFailure;

    if (failure != null) {
      throw failure;
    }

    return session ?? testSession();
  }

  @override
  Future<MeResponse> me() async {
    meCalls++;

    final ApiFailure? failure = meFailure;

    if (failure != null) {
      throw failure;
    }

    return meResponse ?? testMe();
  }

  @override
  Future<void> logout() async {
    logoutCalls++;
  }
}

AuthUser testUser({
  String id = 'user-1',
  String name = 'สมชาย ใจดี',
  String email = 'somchai@example.com',
}) {
  return AuthUser(id: id, name: name, email: email);
}

AuthSession testSession({String token = 'token-abc'}) {
  return AuthSession(user: testUser(), token: token);
}

MeResponse testMe({List<MembershipSummary>? workspaces}) {
  return MeResponse(
    user: testUser(),
    workspaces:
        workspaces ??
        const <MembershipSummary>[
          MembershipSummary(
            id: 'workspace-1',
            workspaceId: 'workspace-1',
            name: 'บ้านใจดี',
            role: 'profile_owner',
            status: 'active',
          ),
        ],
  );
}
