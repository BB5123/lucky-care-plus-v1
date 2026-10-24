import '../network/api_failure.dart';
import 'auth_models.dart';

enum AuthStatus { unauthenticated, authenticating, authenticated }

/// Immutable snapshot of the global session.
///
/// The server remains the authority on access; this only tracks whether a
/// bearer token exists and who it belongs to.
class AuthState {
  const AuthState({
    this.status = AuthStatus.unauthenticated,
    this.user,
    this.token,
    this.failure,
  });

  final AuthStatus status;
  final AuthUser? user;
  final String? token;
  final ApiFailure? failure;

  bool get isAuthenticated =>
      status == AuthStatus.authenticated && token != null;

  bool get isLoading => status == AuthStatus.authenticating;

  bool get hasToken => token != null && token!.isNotEmpty;
}
